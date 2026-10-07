<?php
declare(strict_types=1);

namespace RSM\Rsmbouncemailprocessor\Task;

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
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Undkonsorten\CuteMailing\Domain\Repository\NewsletterRepository;


/**
 * Class ProcessBounceMail
 * @package RSM\Rsmbouncemailprocessor\Scheduler
 * @author Ralph Brugger <ralph.brugger@ressourcenmangel.de>
 */
class ProcessBounceMail extends AbstractTask
{
    use LazyServicesTrait;


    public $conf;
    /**
     * newsletterRepository object
     * @var NewsletterRepository
     */
    protected NewsletterRepository $newsletterRepository;



    /**
     * initializes the class
     *
     */
    public function initClass(): void
    {

        // TS Setup
        $this->conf = $this->getModuleTs('tx_rsmbouncemailprocessor');

        /** @var NewsletterRepository $newsletterRepository */
        $this->newsletterRepository = GeneralUtility::makeInstance(NewsletterRepository::class);

    }


    /**
     * execute the scheduler task.
     *
     * @return bool
     */
    public function execute(): bool
    {

        // defaults
        $result = false;
        $newsletter = null;
        $recipientLists = [];

        // init
        $this->initClass();

        // first, query all newsletters grouped by recipient_list to get the recipient lists
        $queryBuilderReadNewsletter = $this->connectionPool()->getQueryBuilderForTable('tx_cutemailing_domain_model_newsletter');
        $queryBuilderReadNewsletter = $queryBuilderReadNewsletter
            ->select('uid', 'recipient_list')
            ->from('tx_cutemailing_domain_model_newsletter')
            ->where(
                $queryBuilderReadNewsletter->expr()->eq('hidden', 0),
                $queryBuilderReadNewsletter->expr()->eq('deleted', 0),
            )
            ->groupBy('recipient_list')
            ->executeQuery();

        // save the recipient lists
        while ($row = $queryBuilderReadNewsletter->fetchAssociative()) {
            if ($row['uid']) {
                $newsletter = $this->newsletterRepository->findByUid($row['uid']);
                if ($newsletter) {
                    // get the newsletters recipient list
                    $recipientList = $newsletter->getRecipientList();
                    if ($recipientList) {
                        $recipientLists[$recipientList->getUid()] = $recipientList;
                    }
                }
            }
        }
//\TYPO3\CMS\Core\Utility\DebugUtility::debug($recipientLists, '$recipientLists');

        // Walk through the recipientreport and get those rcords that reached their limits
        $connection = $this->connectionPool()->getConnectionForTable('tx_rsmbouncemailprocessor_domain_model_recipientreport');

        // Walk through all the delete limits
        if (isset($this->conf['settings.']['deletelimits.'])) {
            foreach ($this->conf['settings.']['deletelimits.'] as $key => $limit) {

                // checkt if valid
                if ($key !== '' && $limit > 0) {

                    // query the affected records
                    $queryBuilderReadRecipientreport = $this->connectionPool()->getQueryBuilderForTable('tx_rsmbouncemailprocessor_domain_model_recipientreport');
                    $resultReadRecipientreport = $queryBuilderReadRecipientreport
                        ->select('*')
                        ->from('tx_rsmbouncemailprocessor_domain_model_recipientreport')
                        ->where(
                            $queryBuilderReadRecipientreport->expr()->gte($key,
                                $queryBuilderReadRecipientreport->createNamedParameter($limit, Connection::PARAM_INT)),
                        )
                        ->executeQuery();

                    // Alle Records durchlaufen
                    while ($row = $resultReadRecipientreport->fetchAssociative()) {

                        // the email
                        $logvalue = $row[$key];

                        // remove the recipient from all recipient lists we've found
                        $listid = 0;
                        foreach ($recipientLists as $recipientList) {
                            if ($recipientList && $row['email']) {

                                // get the pid
                                $logpid = $recipientList->getRecipientListPage() ?? 0;

                                // remove the recipient
                                try {
                                    $recipientList->removeRecipientByEmail($row['email']);
                                } catch (\Exception) {
                                    $logpid = null;
                                }

                                // delete log enabled?
                                if ($logpid && $listid < 1) {
                                    if (isset($this->conf['settings.']['deletelog.']['enabled']) && isset($this->conf['settings.']['deletelog.']['pid'])) {
                                        if ($this->conf['settings.']['deletelog.']['enabled'] == 1) {
                                            if ($this->conf['settings.']['deletelog.']['pid'] > 0) {

                                                // write delete log entry
                                                $queryBuilderAddLog = $this->connectionPool()->getQueryBuilderForTable('tx_rsmbouncemailprocessor_domain_model_deletelog');
                                                $affectedRows = $queryBuilderAddLog
                                                    ->insert('tx_rsmbouncemailprocessor_domain_model_deletelog')
                                                    ->values([
                                                        'pid' => $this->conf['settings.']['deletelog.']['pid'],
                                                        'email' => $row['email'],
                                                        'tstamp' => time(),
                                                        'crdate' => time(),
                                                        'deletetime' => time(),
                                                        'reasontext' => $key,
                                                        'reasonvalue' => $logvalue
                                                    ])
                                                    ->executeStatement();
                                            }

                                        }
                                    }

                                }
                            }
                            $listid ++;
                        }

                        // Make persistent
                        $this->persistenceManager()->persistAll();

                    }
                }

            }
        }

        return true;
    }


    /**
     * returns the TS settings for a specific path
     * @param string $path the path
     * @return array
     */
    private
    function getModuleTs(
        $path
    ): array {
        $mysettings = [];

        $configurationManager = $this->configurationManager();
        $settings = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
            'rsmbouncemailprocessor');

        if (isset($settings['module.']["$path."])) {
            $mysettings = $settings['module.']["$path."];
        }
        return $mysettings;
    }
}
