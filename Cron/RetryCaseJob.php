<?php

/**
 * Copyright 2016 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Cron;

use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Model\Casedata\FilterCasesByStatusFactory;
use Signifyd\Connect\Model\ProcessCron\CaseData\InReviewFactory;
use Signifyd\Connect\Model\ProcessCron\CaseData\WaitingSubmissionFactory;
use Signifyd\Connect\Model\ProcessCron\CaseData\AsyncWaitingFactory;
use Signifyd\Connect\Model\Casedata;

class RetryCaseJob
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var FilterCasesByStatusFactory
     */
    protected $filterCasesByStatusFactory;

    /**
     * @var InReviewFactory
     */
    protected $inReviewFactory;

    /**
     * @var WaitingSubmissionFactory
     */
    protected $waitingSubmissionFactory;

    /**
     * @var AsyncWaitingFactory
     */
    protected $asyncWaitingFactory;

    /**
     * @var SignifydFlags
     */
    protected $signifydFlags;

    /**
     * RetryCaseJob constructor.
     * @param Logger $logger
     * @param FilterCasesByStatusFactory $filterCasesByStatusFactory
     * @param InReviewFactory $inReviewFactory
     * @param WaitingSubmissionFactory $waitingSubmissionFactory
     * @param AsyncWaitingFactory $asyncWaitingFactory
     */
    public function __construct(
        Logger $logger,
        FilterCasesByStatusFactory $filterCasesByStatusFactory,
        InReviewFactory $inReviewFactory,
        WaitingSubmissionFactory $waitingSubmissionFactory,
        AsyncWaitingFactory $asyncWaitingFactory
    ) {
        $this->logger = $logger;
        $this->filterCasesByStatusFactory = $filterCasesByStatusFactory;
        $this->inReviewFactory = $inReviewFactory;
        $this->waitingSubmissionFactory = $waitingSubmissionFactory;
        $this->asyncWaitingFactory = $asyncWaitingFactory;
    }

    /**
     * Entry point to Cron job
     */
    public function execute()
    {
        $this->logger->debug("CRON: Main retry method called");

        $filterCasesByStatusFactory = $this->filterCasesByStatusFactory->create();
        $asyncWaitingCases = $filterCasesByStatusFactory(Casedata::ASYNC_WAIT);

        $processAsyncWaitingCases = $this->asyncWaitingFactory->create();
        $processAsyncWaitingCases($asyncWaitingCases);

        /**
         * Getting all the cases that were not submitted to Signifyd
         */
        $filterCasesByStatusFactory = $this->filterCasesByStatusFactory->create();
        $waitingCases = $filterCasesByStatusFactory(Casedata::WAITING_SUBMISSION_STATUS);

        $processWaitingSubmission = $this->waitingSubmissionFactory->create();
        $processWaitingSubmission($waitingCases);

        /**
         * Getting all the cases that are awaiting review from Signifyd
         */
        $filterCasesByStatusFactory = $this->filterCasesByStatusFactory->create();
        $inReviewCases = $filterCasesByStatusFactory(Casedata::IN_REVIEW_STATUS);

        $processInReview = $this->inReviewFactory->create();
        $processInReview($inReviewCases);

        $this->signifydFlags->updateCronFlag();
        $this->logger->debug("CRON: Main retry method ended");
    }
}
