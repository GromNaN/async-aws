<?php

namespace AsyncAws\DynamoDb\Result;

use AsyncAws\Core\Result;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\Capacity;
use AsyncAws\DynamoDb\ValueObject\ConsumedCapacity;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ScanOutput extends Result implements \IteratorAggregate
{
    /**
     * An array of item attributes that match the scan criteria. Each element in this array consists of an attribute name
     * and the value for that attribute.
     */
    private $Items = [];

    /**
     * The number of items in the response.
     */
    private $Count;

    /**
     * The number of items evaluated, before any `ScanFilter` is applied. A high `ScannedCount` value with few, or no,
     * `Count` results indicates an inefficient `Scan` operation. For more information, see Count and ScannedCount in the
     * *Amazon DynamoDB Developer Guide*.
     *
     * @see https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/QueryAndScan.html#Count
     */
    private $ScannedCount;

    /**
     * The primary key of the item where the operation stopped, inclusive of the previous result set. Use this value to
     * start a new operation, excluding this value in the new request.
     */
    private $LastEvaluatedKey = [];

    /**
     * The capacity units consumed by the `Scan` operation. The data returned includes the total provisioned throughput
     * consumed, along with statistics for the table and any indexes involved in the operation. `ConsumedCapacity` is only
     * returned if the `ReturnConsumedCapacity` parameter was specified. For more information, see Provisioned Throughput in
     * the *Amazon DynamoDB Developer Guide*.
     *
     * @see https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/ProvisionedThroughputIntro.html
     */
    private $ConsumedCapacity;

    public function getConsumedCapacity(): ?ConsumedCapacity
    {
        $this->initialize();

        return $this->ConsumedCapacity;
    }

    public function getCount(): ?int
    {
        $this->initialize();

        return $this->Count;
    }

    /**
     * @param bool $currentPageOnly When true, iterates over items of the current page. Otherwise also fetch items in the next pages.
     *
     * @return iterable<AttributeValue[]>
     */
    public function getItems(bool $currentPageOnly = false): iterable
    {
        if ($currentPageOnly) {
            $this->initialize();
            yield from $this->Items;

            return;
        }

        $client = $this->awsClient;
        if (!$client instanceof DynamoDbClient) {
            throw new \InvalidArgumentException('missing client injected in paginated result');
        }
        if (!$this->input instanceof ScanInput) {
            throw new \InvalidArgumentException('missing last request injected in paginated result');
        }
        $input = clone $this->input;
        $page = $this;
        while (true) {
            if ($page->getLastEvaluatedKey()) {
                $input->setExclusiveStartKey($page->getLastEvaluatedKey());

                $this->registerPrefetch($nextPage = $client->Scan($input));
            } else {
                $nextPage = null;
            }

            yield from $page->getItems(true);

            if (null === $nextPage) {
                break;
            }

            $this->unregisterPrefetch($nextPage);
            $page = $nextPage;
        }
    }

    /**
     * Iterates over Items.
     *
     * @return \Traversable<AttributeValue[]>
     */
    public function getIterator(): \Traversable
    {
        $client = $this->awsClient;
        if (!$client instanceof DynamoDbClient) {
            throw new \InvalidArgumentException('missing client injected in paginated result');
        }
        if (!$this->input instanceof ScanInput) {
            throw new \InvalidArgumentException('missing last request injected in paginated result');
        }
        $input = clone $this->input;
        $page = $this;
        while (true) {
            if ($page->getLastEvaluatedKey()) {
                $input->setExclusiveStartKey($page->getLastEvaluatedKey());

                $this->registerPrefetch($nextPage = $client->Scan($input));
            } else {
                $nextPage = null;
            }

            yield from $page->getItems(true);

            if (null === $nextPage) {
                break;
            }

            $this->unregisterPrefetch($nextPage);
            $page = $nextPage;
        }
    }

    /**
     * @return AttributeValue[]
     */
    public function getLastEvaluatedKey(): array
    {
        $this->initialize();

        return $this->LastEvaluatedKey;
    }

    public function getScannedCount(): ?int
    {
        $this->initialize();

        return $this->ScannedCount;
    }

    protected function populateResult(ResponseInterface $response, HttpClientInterface $httpClient): void
    {
        $data = $response->toArray(false);

        $this->Items = empty($data['Items']) ? [] : (function (array $json): array {
            $items = [];
            foreach ($json as $item) {
                $a = empty($item) ? [] : (function (array $json): array {
                    $items = [];
                    foreach ($json as $name => $value) {
                        $items[$name] = AttributeValue::create($value);
                    }

                    return $items;
                })($item);
                if (null !== $a) {
                    $items[] = $a;
                }
            }

            return $items;
        })($data['Items']);
        $this->Count = isset($data['Count']) ? (int) $data['Count'] : null;
        $this->ScannedCount = isset($data['ScannedCount']) ? (int) $data['ScannedCount'] : null;
        $this->LastEvaluatedKey = empty($data['LastEvaluatedKey']) ? [] : (function (array $json): array {
            $items = [];
            foreach ($json as $name => $value) {
                $items[$name] = AttributeValue::create($value);
            }

            return $items;
        })($data['LastEvaluatedKey']);
        $this->ConsumedCapacity = empty($data['ConsumedCapacity']) ? null : new ConsumedCapacity([
            'TableName' => isset($data['ConsumedCapacity']['TableName']) ? (string) $data['ConsumedCapacity']['TableName'] : null,
            'CapacityUnits' => isset($data['ConsumedCapacity']['CapacityUnits']) ? (float) $data['ConsumedCapacity']['CapacityUnits'] : null,
            'ReadCapacityUnits' => isset($data['ConsumedCapacity']['ReadCapacityUnits']) ? (float) $data['ConsumedCapacity']['ReadCapacityUnits'] : null,
            'WriteCapacityUnits' => isset($data['ConsumedCapacity']['WriteCapacityUnits']) ? (float) $data['ConsumedCapacity']['WriteCapacityUnits'] : null,
            'Table' => empty($data['ConsumedCapacity']['Table']) ? null : new Capacity([
                'ReadCapacityUnits' => isset($data['ConsumedCapacity']['Table']['ReadCapacityUnits']) ? (float) $data['ConsumedCapacity']['Table']['ReadCapacityUnits'] : null,
                'WriteCapacityUnits' => isset($data['ConsumedCapacity']['Table']['WriteCapacityUnits']) ? (float) $data['ConsumedCapacity']['Table']['WriteCapacityUnits'] : null,
                'CapacityUnits' => isset($data['ConsumedCapacity']['Table']['CapacityUnits']) ? (float) $data['ConsumedCapacity']['Table']['CapacityUnits'] : null,
            ]),
            'LocalSecondaryIndexes' => empty($data['ConsumedCapacity']['LocalSecondaryIndexes']) ? [] : (function (array $json): array {
                $items = [];
                foreach ($json as $name => $value) {
                    $items[$name] = Capacity::create($value);
                }

                return $items;
            })($data['ConsumedCapacity']['LocalSecondaryIndexes']),
            'GlobalSecondaryIndexes' => empty($data['ConsumedCapacity']['GlobalSecondaryIndexes']) ? [] : (function (array $json): array {
                $items = [];
                foreach ($json as $name => $value) {
                    $items[$name] = Capacity::create($value);
                }

                return $items;
            })($data['ConsumedCapacity']['GlobalSecondaryIndexes']),
        ]);
    }
}
