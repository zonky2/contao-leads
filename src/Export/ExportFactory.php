<?php

namespace Terminal42\LeadsBundle\Export;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;

class ExportFactory
{
    /**
     * @var iterable
     */
    private $services;

    /**
     * @var ExportInterface[]
     */
    private $instances;

    /**
     * @var Connection
     */
    private $database;

    public function __construct(iterable $services, Connection $database)
    {
        $this->database = $database;
        $this->services = $services;
    }

    /**
     * @return ExportInterface[]
     */
    public function getServices(): array
    {
        $this->loadServices();

        return $this->instances;
    }

    public function createForType(string $type): ExportInterface
    {
        $this->loadServices();

        if (!isset($this->instances[$type])) {
            throw new \InvalidArgumentException(sprintf('Export type "%s" does not exist.', $type));
        }

        return $this->instances[$type];
    }

    public function buildConfig(int $configId): \stdClass
    {
        $qb = $this->database->createQueryBuilder();

        $qb
            ->select('e.*')
            ->addSelect('f.leadMaster AS master')
            ->from('tl_lead_export', 'e')
            ->leftJoin('e', 'tl_form', 'f', 'tl_form.id = e.pid')
            ->where('e.id = :id')
            ->setParameter('id', $configId)
        ;

        $result = $qb->execute();

        if (!$result->rowCount()) {
            throw new \InvalidArgumentException(sprintf('Export config ID %s not found', $configId));
        }

        $config = $result->fetch(FetchMode::STANDARD_OBJECT);

        $config->master      = $config->master ?: $config->pid;
        $config->fields      = deserialize($config->fields, true);
        $config->tokenFields = deserialize($config->tokenFields, true);

        return $config;
    }

    private function loadServices()
    {
        if (null !== $this->instances) {
            return;
        }

        $this->instances = [];

        foreach ($this->services as $service) {
            if (!$service instanceof ExportInterface) {
                throw new \RuntimeException(sprintf('"%s" must implement %s', get_class($service), ExportInterface::class));
            }

            if (!$service->isAvailable()) {
                return;
            }

            $this->instances[$service->getType()] = $service;
        }
    }
}
