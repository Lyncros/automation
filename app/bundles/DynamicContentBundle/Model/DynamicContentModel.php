<?php
/**
 * @copyright   2016 Mautic Contributors. All rights reserved.
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Mautic\DynamicContentBundle\Model;

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\DynamicContentBundle\DynamicContentEvents;
use Mautic\DynamicContentBundle\Entity\DynamicContent;
use Mautic\DynamicContentBundle\Entity\DynamicContentRepository;
use Mautic\DynamicContentBundle\Entity\Stat;
use Mautic\DynamicContentBundle\Event\DynamicContentEvent;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class DynamicContentModel extends FormModel
{
    /**
     * {@inheritdoc}
     *
     * @return DynamicContentRepository
     */
    public function getRepository()
    {
        /** @var DynamicContentRepository $repo */
        $repo = $this->em->getRepository('MauticDynamicContentBundle:DynamicContent');

        $repo->setTranslator($this->translator);

        return $repo;
    }

    /**
     * @return \Mautic\DynamicContentBundle\Entity\StatRepository
     */
    public function getStatRepository()
    {
        return $this->em->getRepository('MauticDynamicContentBundle:Stat');
    }

    /**
     * Here just so PHPStorm calms down about type hinting.
     * 
     * @param null $id
     *
     * @return null|DynamicContent
     */
    public function getEntity($id = null)
    {
        return parent::getEntity($id);
    }

    /**
     * {@inheritdoc}
     *
     * @param       $entity
     * @param       $formFactory
     * @param null  $action
     * @param array $options
     * 
     * @return mixed
     * 
     * @throws \InvalidArgumentException
     */
    public function createForm($entity, $formFactory, $action = null, $options = [])
    {
        if (!$entity instanceof DynamicContent) {
            throw new \InvalidArgumentException('Entity must be of class DynamicContent');
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create('dwc', $entity, $options);
    }

    /**
     * Get the variant parent/children.
     *
     * @param DynamicContent $entity
     *
     * @return array
     */
    public function getVariants(DynamicContent $entity)
    {
        $parent = $entity->getVariantParent();

        if (!empty($parent)) {
            $children = $parent->getVariantChildren();
        } else {
            $parent = $entity;
            $children = $entity->getVariantChildren();
        }

        if (empty($children)) {
            $children = [];
        }

        return [$parent, $children];
    }

    /**
     * @param DynamicContent $dwc
     * @param Lead           $lead
     * @param                $slot
     */
    public function setSlotContentForLead(DynamicContent $dwc, Lead $lead, $slot)
    {
        $qb = $this->em->getConnection()->createQueryBuilder();

        $qb->insert(MAUTIC_TABLE_PREFIX.'dynamic_content_lead_data')
            ->values([
                'lead_id' => $lead->getId(),
                'dynamic_content_id' => $dwc->getId(),
                'slot' => ':slot',
                'date_added' => $qb->expr()->literal((new \DateTime())->format('Y-m-d H:i:s'))
            ])->setParameter('slot', $slot);
        
        $qb->execute();
    }

    /**
     * @param      $slot
     * @param Lead $lead
     * 
     * @return DynamicContent
     */
    public function getSlotContentForLead($slot, Lead $lead)
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        
        $qb->select('dc.id, dc.content')
            ->from(MAUTIC_TABLE_PREFIX.'dynamic_content', 'dc')
            ->leftJoin('dc', MAUTIC_TABLE_PREFIX.'dynamic_content_lead_data', 'dcld', 'dcld.dynamic_content_id = dc.id')
            ->andWhere($qb->expr()->eq('dcld.slot', ':slot'))
            ->andWhere($qb->expr()->eq('dcld.lead_id', ':lead_id'))
            ->setParameter('slot', $slot)
            ->setParameter('lead_id', $lead->getId())
            ->orderBy('dcld.date_added', 'DESC')
            ->addOrderBy('dcld.id', 'DESC');

        return $qb->execute()->fetch();
    }

    /**
     * @param DynamicContent $dynamicContent
     * @param Lead           $lead
     * @param string         $source
     */
    public function createStatEntry(DynamicContent $dynamicContent, Lead $lead, $source = null)
    {
        $stat = new Stat();
        $stat->setDateSent(new \DateTime());
        $stat->setLead($lead);
        $stat->setDynamicContent($dynamicContent);
        $stat->setSource($source);

        $this->getStatRepository()->saveEntity($stat);
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $entity
     * @param $isNew
     * @param $event
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null)
    {
        if (!$entity instanceof DynamicContent) {
            throw new MethodNotAllowedHttpException(['Dynamic Content']);
        }

        switch ($action) {
            case "pre_save":
                $name = DynamicContentEvents::PRE_SAVE;
                break;
            case "post_save":
                $name = DynamicContentEvents::POST_SAVE;
                break;
            case "pre_delete":
                $name = DynamicContentEvents::PRE_DELETE;
                break;
            case "post_delete":
                $name = DynamicContentEvents::POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new DynamicContentEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }

            $this->dispatcher->dispatch($name, $event);

            return $event;
        } else {
            return null;
        }
    }

    /**
     * Joins the page table and limits created_by to currently logged in user
     *
     * @param QueryBuilder $q
     */
    public function limitQueryToCreator(QueryBuilder &$q)
    {
        $q->join('t', MAUTIC_TABLE_PREFIX.'pages', 'p', 'p.id = t.page_id')
            ->andWhere('p.created_by = :userId')
            ->setParameter('userId', $this->user->getId());
    }

    /**
     * Get line chart data of hits
     *
     * @param char      $unit   {@link php.net/manual/en/function.date.php#refsect1-function.date-parameters}
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param string    $dateFormat
     * @param array     $filter
     * @param boolean   $canViewOthers
     *
     * @return array
     */
    public function getHitsLineChartData($unit, \DateTime $dateFrom, \DateTime $dateTo, $dateFormat = null, $filter = array(), $canViewOthers = true)
    {
        $flag = null;

        if (isset($filter['flag'])) {
            $flag = $filter['flag'];
            unset($filter['flag']);
        }

        $chart = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query = $chart->getChartQuery($this->em->getConnection());

        if (!$flag || $flag === 'total_and_unique') {
            $q = $query->prepareTimeDataQuery('dynamic_content_stats', 'date_sent', $filter);

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);
            }

            $data = $query->loadAndBuildTimeData($q);
            $chart->setDataset($this->translator->trans('mautic.dynamicContent.show.total.views'), $data);
        }

        if ($flag === 'unique' || $flag === 'total_and_unique') {
            $q = $query->prepareTimeDataQuery('dynamic_content_stats', 'date_sent', $filter);
            $q->groupBy('t.lead_id, t.date_sent');

            if (!$canViewOthers) {
                $this->limitQueryToCreator($q);
            }

            $data = $query->loadAndBuildTimeData($q);
            $chart->setDataset($this->translator->trans('mautic.dynamicContent.show.unique.views'), $data);
        }

        return $chart->render();
    }
}