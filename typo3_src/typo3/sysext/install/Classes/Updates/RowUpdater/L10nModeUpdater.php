<?php
declare(strict_types=1);
namespace TYPO3\CMS\Install\Updates\RowUpdater;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Localization\DataMapProcessor;
use TYPO3\CMS\Core\DataHandling\Localization\State;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Install\Service\LoadTcaService;
use TYPO3\CMS\Lang\LanguageService;

/**
 * Migrate values for database records having columns
 * using "l10n_mode" set to "mergeIfNotBlank" or "exclude".
 */
class L10nModeUpdater implements RowUpdaterInterface
{
    /**
     * @var array Full, migrated TCA as prepared by upgrade wizard controller
     */
    protected $migratedTca;

    /**
     * @var array Full, but NOT migrated TCA
     */
    protected $notMigratedTca;

    /**
     * List of tables with information about to migrate fields.
     * Created during hasPotentialUpdateForTable(), used in updateTableRow()
     *
     * @var array
     */
    protected $payload = [];

    /**
     * Prepare non-migrated TCA to be used in 'hasPotentialUpdateForTable' step
     */
    public function __construct()
    {
        $this->migratedTca = $GLOBALS['TCA'];
        $loadTcaService = GeneralUtility::makeInstance(LoadTcaService::class);
        $loadTcaService->loadExtensionTablesWithoutMigration();
        $this->notMigratedTca = $GLOBALS['TCA'];
        $GLOBALS['TCA'] = $this->migratedTca;
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate values in database records having "l10n_mode"'
            . ' either set to "exclude" or "mergeIfNotBlank"';
    }

    /**
     * Return true if a table needs modifications.
     *
     * @param string $tableName Table name to check
     * @return bool True if this table has fields to migrate
     */
    public function hasPotentialUpdateForTable(string $tableName): bool
    {
        $GLOBALS['TCA'] = $this->notMigratedTca;
        $this->payload[$tableName] = $this->getL10nModePayloadForTable($tableName);
        $GLOBALS['TCA'] = $this->migratedTca;
        return !empty($this->payload[$tableName]['localizations']);
    }

    /**
     * Update single row if needed
     *
     * @param string $tableName
     * @param array $inputRow Given row data
     * @return array Modified row data
     */
    public function updateTableRow(string $tableName, array $inputRow): array
    {
        $currentId = $inputRow['uid'];

        if (empty($this->payload[$tableName]['localizations'][$currentId])) {
            return $inputRow;
        }

        // disable DataHandler hooks for processing this update
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'])) {
            $dataHandlerHooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'];
            unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']);
        }

        if (empty($GLOBALS['LANG'])) {
            $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageService::class);
        }
        if (!empty($GLOBALS['BE_USER'])) {
            $adminUser = $GLOBALS['BE_USER'];
        }
        // the admin user is required to defined workspace state when working with DataHandler
        $fakeAdminUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $fakeAdminUser->user = ['uid' => 0, 'username' => '_migration_', 'admin' => 1];
        $fakeAdminUser->workspace = ($inputRow['t3ver_wsid'] ?? 0);
        $GLOBALS['BE_USER'] = $fakeAdminUser;

        $tablePayload = $this->payload[$tableName];
        $parentId = $tablePayload['localizations'][$currentId];
        $parentTableName = ($tableName === 'pages_language_overlay' ? 'pages' : $tableName);

        $liveId = $currentId;
        if (!empty($inputRow['t3ver_wsid'])
            && !empty($inputRow['t3ver_oid'])
            && !VersionState::cast($inputRow['t3ver_state'])
                ->equals(VersionState::NEW_PLACEHOLDER_VERSION)) {
            $liveId = $inputRow['t3ver_oid'];
        }

        $dataMap = [];

        // simulate modifying a parent record to trigger dependent updates
        if (in_array('exclude', $tablePayload['fieldModes'])) {
            $parentRecord = $this->getRow($parentTableName, $parentId);
            foreach ($tablePayload['fieldModes'] as $fieldName => $fieldMode) {
                if ($fieldMode !== 'exclude') {
                    continue;
                }
                $dataMap[$parentTableName][$parentId][$fieldName] = $parentRecord[$fieldName];
            }
            $dataMap = DataMapProcessor::instance($dataMap, $fakeAdminUser)->process();
            unset($dataMap[$parentTableName][$parentId]);
            if (empty($dataMap[$parentTableName])) {
                unset($dataMap[$parentTableName]);
            }
        }

        // define localization states and thus trigger updates later
        if (State::isApplicable($tableName)) {
            $stateUpdates = [];
            foreach ($tablePayload['fieldModes'] as $fieldName => $fieldMode) {
                if ($fieldMode !== 'mergeIfNotBlank') {
                    continue;
                }
                if (!empty($inputRow[$fieldName])) {
                    $stateUpdates[$fieldName] = State::STATE_CUSTOM;
                } else {
                    $stateUpdates[$fieldName] = State::STATE_PARENT;
                }
            }

            $languageState = State::create($tableName);
            $languageState->update($stateUpdates);
            // only consider field names that still used mergeIfNotBlank
            $modifiedFieldNames = array_intersect(
                array_keys($tablePayload['fieldModes']),
                $languageState->getModifiedFieldNames()
            );
            if (!empty($modifiedFieldNames)) {
                $dataMap = [
                    $tableName => [
                        $liveId => [
                            'l10n_state' => $languageState->toArray()
                        ]
                    ]
                ];
            }
        }

        if (empty($dataMap)) {
            return $inputRow;
        }

        // let DataHandler process all updates, $inputRow won't change
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->enableLogging = false;
        $dataHandler->start($dataMap, [], $fakeAdminUser);
        $dataHandler->process_datamap();

        if (!empty($dataHandlerHooks)) {
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'] = $dataHandlerHooks;
        }
        if (!empty($adminUser)) {
            $GLOBALS['BE_USER'] = $adminUser;
        }

        // the unchanged(!) state as submitted
        return $inputRow;
    }

    /**
     * Retrieves field names grouped per table name having "l10n_mode" set
     * to a relevant value that shall be migrated in database records.
     *
     * Resulting array is structured like this:
     * + fields: [field a, field b, ...]
     * + sources
     *   + source uid: [localization uid, localization uid, ...]
     *
     * @param string $tableName Table name
     * @return array Payload information for this table
     * @throws \RuntimeException
     */
    protected function getL10nModePayloadForTable(string $tableName): array
    {
        if (!is_array($GLOBALS['TCA'][$tableName])) {
            throw new \RuntimeException(
                'Globals TCA of given table name must exist',
                1484176136
            );
        }

        $tableDefinition = $GLOBALS['TCA'][$tableName];
        $languageFieldName = ($tableDefinition['ctrl']['languageField'] ?? null);
        $parentFieldName = ($tableDefinition['ctrl']['transOrigPointerField'] ?? null);

        if (
            empty($tableDefinition['columns'])
            || !is_array($tableDefinition['columns'])
            || empty($languageFieldName)
            || empty($parentFieldName)
        ) {
            return [];
        }

        $fieldModes = [];
        foreach ($tableDefinition['columns'] as $fieldName => $fieldConfiguration) {
            if (
                empty($fieldConfiguration['l10n_mode'])
                || empty($fieldConfiguration['config']['type'])
            ) {
                continue;
            }
            if (
                $fieldConfiguration['l10n_mode'] === 'exclude'
                || $fieldConfiguration['l10n_mode'] === 'mergeIfNotBlank'
            ) {
                $fieldModes[$fieldName] = $fieldConfiguration['l10n_mode'];
            }
        }

        if (empty($fieldModes)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->from($tableName);

        $parentFieldName = $tableDefinition['ctrl']['transOrigPointerField'];
        $selectFieldNames = ['uid', $parentFieldName];

        $predicates = [
            $queryBuilder->expr()->gt(
                $tableDefinition['ctrl']['languageField'],
                $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
            ),
            $queryBuilder->expr()->gt(
                $parentFieldName,
                $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
            )
        ];

        if (!empty($tableDefinition['ctrl']['versioningWS'])) {
            $selectFieldNames = array_merge(
                $selectFieldNames,
                ['t3ver_wsid', 't3ver_oid', 't3ver_state']
            );
            $predicates[] = $queryBuilder->expr()->orX(
                $queryBuilder->expr()->eq(
                    't3ver_state',
                    $queryBuilder->createNamedParameter(
                        VersionState::NEW_PLACEHOLDER_VERSION,
                        \PDO::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    't3ver_state',
                    $queryBuilder->createNamedParameter(
                        VersionState::DEFAULT_STATE,
                        \PDO::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    't3ver_state',
                    $queryBuilder->createNamedParameter(
                        VersionState::MOVE_POINTER,
                        \PDO::PARAM_INT
                    )
                )
            );
        }

        $statement = $queryBuilder
            ->select(...$selectFieldNames)
            ->andWhere(...$predicates)
            ->execute();

        $payload = [];

        foreach ($statement as $row) {
            $translationId = $row['uid'];
            $parentId = $row[$parentFieldName];
            $payload['localizations'][$translationId] = $parentId;
        }
        if (!empty($payload['localizations'])) {
            $payload['fieldModes'] = $fieldModes;
        }

        return $payload;
    }

    /**
     * @param string $tableName
     * @param int $id
     * @return array
     */
    protected function getRow(string $tableName, int $id)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();

        $statement = $queryBuilder
            ->select('*')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT)
                )
            )
            ->execute();

        return $statement->fetch();
    }
}
