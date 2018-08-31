<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\IntegrationsBundle\Sync\Mapping;

use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\IntegrationsBundle\Entity\ObjectMapping;
use MauticPlugin\IntegrationsBundle\Entity\ObjectMappingRepository;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Mapping\MappingManualDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Mapping\UpdatedObjectMappingDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Report\ObjectDAO;
use MauticPlugin\IntegrationsBundle\Sync\Exception\FieldNotFoundException;
use MauticPlugin\IntegrationsBundle\Sync\SyncDataExchange\MauticSyncDataExchange;

class MappingHelper
{
    /**
     * @var FieldModel
     */
    private $fieldModel;

    /**
     * @var LeadRepository
     */
    private $leadRepository;

    /**
     * @var ObjectMappingRepository
     */
    private $objectMappingRepository;

    /**
     * MappingHelper constructor.
     *
     * @param FieldModel              $fieldModel
     * @param LeadRepository          $leadRepository
     * @param ObjectMappingRepository $objectMappingRepository
     */
    public function __construct(FieldModel $fieldModel, LeadRepository $leadRepository, ObjectMappingRepository $objectMappingRepository)
    {
        $this->fieldModel              = $fieldModel;
        $this->leadRepository          = $leadRepository;
        $this->objectMappingRepository = $objectMappingRepository;
    }

    /**
     * @param MappingManualDAO $mappingManualDAO
     * @param string           $internalObjectName
     * @param ObjectDAO        $integrationObjectDAO
     *
     * @return ObjectDAO
     */
    public function findMauticObject(MappingManualDAO $mappingManualDAO, string $internalObjectName, ObjectDAO $integrationObjectDAO)
    {
        // Check if this contact is already tracked
        if ($internalObject = $this->objectMappingRepository->getInternalObject(
            $mappingManualDAO->getIntegration(),
            $integrationObjectDAO->getObject(),
            $integrationObjectDAO->getObjectId(),
            $internalObjectName
        )) {
            return new ObjectDAO(
                $internalObjectName,
                $internalObject['internal_object_id'],
                new \DateTime($internalObject['last_sync_date'], new \DateTimeZone('UTC'))
            );
        }

        // We don't know who this is so search Mautic
        $uniqueIdentifierFields = $this->fieldModel->getUniqueIdentifierFields(['object' => $internalObjectName]);
        $identifiers            = [];

        foreach ($uniqueIdentifierFields as $field => $fieldLabel) {
            try {
                $integrationField = $mappingManualDAO->getIntegrationMappedField($internalObjectName, $integrationObjectDAO->getObject(), $field);
                if ($integrationValue = $integrationObjectDAO->getField($integrationField)) {
                    $identifiers[$integrationField] = $integrationValue->getValue()->getNormalizedValue();
                }
            } catch (FieldNotFoundException $e) {}


        }

        if (empty($identifiers)) {
            // No fields found to search for contact so return null
            return new ObjectDAO($internalObjectName, null);
        }

        if (!$foundContacts = $this->leadRepository->getLeadIdsByUniqueFields($identifiers)) {
            // No contacts were found
            return new ObjectDAO($internalObjectName, null);
        }

        // Match found!
        $objectId = $foundContacts[0]['id'];

        // Let's store the relationship since we know it
        $objectMapping = new ObjectMapping();
        $objectMapping->setLastSyncDate($integrationObjectDAO->getChangeDateTime())
            ->setIntegration($mappingManualDAO->getIntegration())
            ->setIntegrationObjectName($integrationObjectDAO->getObject())
            ->setIntegrationObjectId($integrationObjectDAO->getObjectId())
            ->setInternalObjectName($internalObjectName)
            ->setInternalObjectId($objectId);
        $this->saveObjectMapping($objectMapping);

        return new ObjectDAO($internalObjectName, $objectId);
    }

    /**
     * @param string    $integration
     * @param string    $integrationObjectName
     * @param ObjectDAO $internalObjectDAO
     *
     * @return ObjectDAO
     */
    public function findIntegrationObject(string $integration, string $integrationObjectName, ObjectDAO $internalObjectDAO)
    {
        if ($integrationObject = $this->objectMappingRepository->getIntegrationObject(
            $integration,
            $internalObjectDAO->getObject(),
            $internalObjectDAO->getObjectId(),
            $integrationObjectName
        )) {
            return new ObjectDAO(
                $integrationObjectName,
                $integrationObject['integration_object_id'],
                new \DateTime($integrationObject['last_sync_date'], new \DateTimeZone('UTC'))
            );
        }

        return new ObjectDAO($integrationObjectName, null);
    }

    /**
     * @param ObjectMapping $objectMapping
     */
    public function saveObjectMapping(ObjectMapping $objectMapping)
    {
        $this->objectMappingRepository->saveEntity($objectMapping);
        $this->objectMappingRepository->clear();
    }

    /**
     * @param UpdatedObjectMappingDAO $updatedObjectMappingDAO
     */
    public function updateObjectMapping(UpdatedObjectMappingDAO $updatedObjectMappingDAO)
    {
        $integration = $updatedObjectMappingDAO->getObjectChangeDAO()->getIntegration();

        // This seems backwards but it's not. The ObjectChangeDAO object is based on where the change originated. So if it's Mautic, then the
        // object's main object name and ID are the integration where the mapped object name and ID is Mautic's.
        if (MauticSyncDataExchange::NAME === $integration) {
            $objectMapping = $this->getIntegrationObjectMapping($updatedObjectMappingDAO);
        } else {
            $objectMapping = $this->getInternalObjectMapping($updatedObjectMappingDAO);
        }

        $objectMapping->setLastSyncDate($updatedObjectMappingDAO->getObjectModifiedDate());

        $this->saveObjectMapping($objectMapping);
    }

    /**
     * @param UpdatedObjectMappingDAO $updatedObjectMappingDAO
     *
     * @return ObjectMapping
     */
    private function getIntegrationObjectMapping(UpdatedObjectMappingDAO $updatedObjectMappingDAO)
    {
        $changeObject  = $updatedObjectMappingDAO->getObjectChangeDAO();
        $objectMapping = $this->objectMappingRepository->findOneBy(
            [
                'integration'           => $changeObject->getIntegration(),
                'integrationObjectName' => $changeObject->getObject(),
                'integrationObjectId'   => $changeObject->getObjectId(),
                'internalObjectName'    => $changeObject->getMappedObject(),
                'internalObjectId'      => $changeObject->getMappedObjectId()
            ]
        );

        if (!$objectMapping) {
            // Original mapping wan't found so just create this as a new mapping
            $objectMapping = new ObjectMapping();
            $objectMapping->setLastSyncDate(new \DateTime())
                ->setIntegration($changeObject->getIntegration())
                ->setInternalObjectName($changeObject->getMappedObject())
                ->setInternalObjectId($changeObject->getMappedObjectId());

        }

        $objectMapping
            ->setIntegrationObjectName($updatedObjectMappingDAO->getObjectName())
            ->setIntegrationObjectId($updatedObjectMappingDAO->getObjectId());

        return $objectMapping;
    }

    /**
     * @param UpdatedObjectMappingDAO $updatedObjectMappingDAO
     *
     * @return ObjectMapping
     */
    private function getInternalObjectMapping(UpdatedObjectMappingDAO $updatedObjectMappingDAO)
    {
        $changeObject  = $updatedObjectMappingDAO->getObjectChangeDAO();
        $objectMapping = $this->objectMappingRepository->findOneBy(
            [
                'integration'           => $changeObject->getIntegration(),
                'internalObjectName'    => $changeObject->getObject(),
                'internalObjectId'      => $changeObject->getObjectId(),
                'integrationObjectName' => $changeObject->getMappedObject(),
                'integrationObjectId'   => $changeObject->getMappedObjectId(),
            ]
        );

        if (!$objectMapping) {
            // Original mapping wan't found so just create this as a new mapping
            $objectMapping = new ObjectMapping();
            $objectMapping->setLastSyncDate(new \DateTime())
                ->setIntegration($changeObject->getIntegration())
                ->setIntegrationObjectName($changeObject->getMappedObject())
                ->setIntegrationObjectName($changeObject->getMappedObjectId());
        }

        $objectMapping
            ->setInternalObjectName($updatedObjectMappingDAO->getObjectName())
            ->setInternalObjectId($updatedObjectMappingDAO->getObjectId());

        return $objectMapping;
    }
}