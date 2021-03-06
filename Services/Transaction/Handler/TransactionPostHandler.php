<?php
/*
 * This file is part of the ecentria group, inc. software.
 *
 * (c) 2015, ecentria group, inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ecentria\Libraries\EcentriaRestBundle\Services\Transaction\Handler;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\ORM\EntityManager;

use Ecentria\Libraries\EcentriaRestBundle\Entity\Transaction,
    Ecentria\Libraries\EcentriaRestBundle\Model\CollectionResponse,
    Ecentria\Libraries\EcentriaRestBundle\Model\CRUD\CrudEntityInterface,
    Ecentria\Libraries\EcentriaRestBundle\Services\ErrorBuilder,
    Ecentria\Libraries\EcentriaRestBundle\Services\NoticeBuilder,
    Ecentria\Libraries\EcentriaRestBundle\Services\UUID;

use Gedmo\Exception\FeatureNotImplementedException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Transaction POST handler
 *
 * @author Sergey Chernecov <sergey.chernecov@intexsys.lv>
 */
class TransactionPostHandler implements TransactionHandlerInterface
{
    /**
     * Constructor
     *
     * @param EntityManager $entityManager entityManager
     * @param ErrorBuilder  $errorBuilder  errorBuilder
     * @param NoticeBuilder $noticeBuilder noticeBuilder
     */
    public function __construct(
        EntityManager $entityManager,
        ErrorBuilder $errorBuilder,
        NoticeBuilder $noticeBuilder
    ) {
        $this->entityManager = $entityManager;
        $this->errorBuilder = $errorBuilder;
        $this->noticeBuilder = $noticeBuilder;
    }

    /**
     * Supports method
     *
     * @return string
     */
    public function supports()
    {
        return 'POST';
    }

    /**
     * Handle
     *
     * @param Transaction                         $transaction Transaction
     * @param CrudEntityInterface|ArrayCollection $data        Data
     * @param ConstraintViolationList|null        $violations  Violations
     *
     * @throws FeatureNotImplementedException
     *
     * @return CrudEntityInterface|CollectionResponse
     */
    public function handle(Transaction $transaction, $data, ConstraintViolationList $violations = null)
    {
        $this->errorBuilder->processViolations($violations);
        $this->errorBuilder->setTransactionErrors($transaction);

        $success = !$this->errorBuilder->hasErrors();
        $status = $success ? Transaction::STATUS_CREATED : Transaction::STATUS_CONFLICT;

        $transaction->setStatus($status);
        $transaction->setSuccess($success);

        if ($data instanceof ArrayCollection) {
            if ($data->isEmpty()) {
                $data = $this->handleEmptyCollection($transaction, $data);
            } else {
                $data = $this->handleCollection($transaction, $data);
            }
        } else {
            throw new FeatureNotImplementedException(
                get_class($data) . ' class is not supported by transactions (POST). Instance of ArrayCollection needed.'
            );
        }

        if (!$transaction->getSuccess()) {
            $data->getItems()->clear();
        }

        return $data;
    }

    /**
     * Handle collection
     *
     * @param Transaction                           $baseTransaction Base transaction
     * @param ArrayCollection|CrudEntityInterface[] $data            Data
     *
     * @return ArrayCollection|CollectionResponse
     */
    private function handleCollection(Transaction $baseTransaction, ArrayCollection $data)
    {
        foreach ($data as $entity) {

            $transaction = clone $baseTransaction;

            $transaction->setRequestSource(Transaction::SOURCE_SERVICE);
            $transaction->setId(UUID::generate());
            $transaction->setRequestId(microtime());
            $transaction->setRelatedId($entity->getId());

            $errors = $this->errorBuilder->getEntityErrors($entity->getId());
            $messages = new ArrayCollection();

            $success = $errors->isEmpty();
            $status = $success ? Transaction::STATUS_CREATED : Transaction::STATUS_CONFLICT;

            $transaction->setStatus($status);
            $transaction->setSuccess($success);

            if ($success) {
                $this->noticeBuilder->addSuccess();
            } else {
                $messages->set('errors', $errors);
                $this->noticeBuilder->addFail();
            }

            $transaction->setMessages($messages);
            $this->entityManager->persist($transaction);
            $entity->setTransaction($transaction);
        }

        $this->noticeBuilder->setTransactionNotices($baseTransaction);
        $data = new CollectionResponse($data);
        $data->setShowAssociations(true);

        return $data;
    }

    /**
     * Handle empty collection
     *
     * @param Transaction                           $baseTransaction Base transaction
     * @param ArrayCollection|CrudEntityInterface[] $data            Data
     *
     * @return ArrayCollection|CollectionResponse
     */
    private function handleEmptyCollection(Transaction $baseTransaction, ArrayCollection $data)
    {
        $this->noticeBuilder->setTransactionNotices($baseTransaction);
        $data = new CollectionResponse($data);
        $data->setShowAssociations(true);

        $baseTransaction->setSuccess(false);
        $baseTransaction->setStatus(Transaction::STATUS_CONFLICT);

        return $data;
    }
}
