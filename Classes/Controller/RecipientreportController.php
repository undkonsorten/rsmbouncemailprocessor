<?php
declare(strict_types=1);

namespace RSM\Rsmbouncemailprocessor\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;


use RSM\Rsmbouncemailprocessor\Domain\Model\Recipientreport;
use RSM\Rsmbouncemailprocessor\Domain\Repository\RecipientreportRepository;
use Undkonsorten\CuteMailing\Domain\Repository\NewsletterRepository;
use Undkonsorten\CuteMailing\Domain\Repository\RecipientListRepositoryInterface;

/**
 * Class RecipientreportController
 */
class RecipientreportController extends ActionController
{

    /**
     * @var RecipientreportRepository|null
     */
    protected $recipientreportRepository = null;

    /**
     * @var NewsletterRepository|null
     */
    protected $newsletterRepository = null;

    /**
     * @var RecipientListRepositoryInterface|null
     */
    protected $recipientListRepository = null;


    /**
     * @param RecipientreportRepository $recipientreportRepository
     * @param NewsletterRepository $newsletterRepository
     * @param RecipientListRepositoryInterface $recipientListRepository
     */
    public function __construct(
        RecipientreportRepository $recipientreportRepository,
        NewsletterRepository $newsletterRepository,
        RecipientListRepositoryInterface $recipientListRepository,
        private readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {
        $this->recipientreportRepository = $recipientreportRepository;
        $this->newsletterRepository = $newsletterRepository;
        $this->recipientListRepository = $recipientListRepository;
    }

    /**
     *
     */
    public function recipientlistAction(): ResponseInterface
    {
        $searchstring = '';
        $searchamount = 0;
        $piVars = [];


        if ($this->request->hasArgument('filter')) {
            $filter =  $this->request->getArgument('filter');
            if (isset($filter['searchstring'])){
                $searchstring = $filter['searchstring'];
                $piVars['filter']['searchstring'] = $searchstring;
            }
            if (isset($filter['searchamount'])){
                $searchamount = intval($filter['searchamount']);
                $piVars['filter']['searchamount'] = $searchamount;
            }

        }
        //$arguments = $this->request->getArguments();
        //\TYPO3\CMS\Core\Utility\DebugUtility::debug($arguments, 'arguments');

        // read bounce mail report
        $currentPid = (int)($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? null);
        if ($currentPid === 0) {
            return new ForwardResponse('choosePage');
        }
        $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $currentPid)->get();
        $recipientreports = $this->recipientreportRepository->findByRootline($rootline, $searchstring, $searchamount);
        if (! count($recipientreports)) {
            return new ForwardResponse('choosePage');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'recipientreports' => $recipientreports,
            'piVars' => $piVars
        ]);
        return $moduleTemplate->renderResponse('Recipientlist');
    }

    /**
     * @param Recipientreport $recipientreport
     * @return ResponseInterface
     * @throws IllegalObjectTypeException
     * @throws StopActionException
     */
    public function deleteAction(Recipientreport $recipientreport): ResponseInterface
    {
        $this->recipientreportRepository->remove($recipientreport);
        $this->addFlashMessage(LocalizationUtility::translate('module.recipientreport.delete.message', 'rsmbouncemailprocessor'), 'Deleted');
        return $this->redirect('recipientlist');
    }

    public function choosePageAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        return $moduleTemplate->renderResponse('ChoosePage');
    }


}
