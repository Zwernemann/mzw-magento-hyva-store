<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Test\Unit\Model\Privacy;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\ConversationalCommerce\Model\Privacy\GdprService;

class GdprServiceTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private GdprService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTableName')->willReturnArgument(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new GdprService($resourceConnection, $logger);
    }

    private function mockSelect(array $returnIds): Select&MockObject
    {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->willReturn($returnIds);

        return $select;
    }

    public function testAnonymizeByEmailReturnsZeroWhenNoConversations(): void
    {
        $this->mockSelect([]);

        $count = $this->service->anonymizeByEmail('nobody@example.com');

        $this->assertSame(0, $count);
    }

    public function testAnonymizeByEmailReturnsCorrectCount(): void
    {
        $this->mockSelect([1, 2, 3]);

        $this->connection->method('beginTransaction');
        $this->connection->method('commit');
        $this->connection->expects($this->exactly(3))
            ->method('update');   // cc_conversation, cc_conversation_message, cc_llm_usage_log
        $this->connection->expects($this->once())
            ->method('delete');   // cc_customer_alias_email

        $count = $this->service->anonymizeByEmail('customer@example.com');

        $this->assertSame(3, $count);
    }

    public function testAnonymizeByEmailReplacesEmailWithHash(): void
    {
        $this->mockSelect([10]);

        $capturedUpdates = [];
        $this->connection->method('beginTransaction');
        $this->connection->method('commit');
        $this->connection->method('update')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedUpdates) {
                $capturedUpdates[$table] = $data;
            });
        $this->connection->method('delete');

        $this->service->anonymizeByEmail('test@example.com');

        $anonEmail = $capturedUpdates['cc_conversation']['customer_email'] ?? '';
        $this->assertStringStartsWith('anon-', $anonEmail);
        $this->assertStringEndsWith('@deleted.invalid', $anonEmail);
        $this->assertSame('anonymized', $capturedUpdates['cc_conversation']['status'] ?? '');
        $this->assertNull($capturedUpdates['cc_conversation']['magento_customer_id'] ?? 'NOT_NULL');
    }

    public function testAnonymizeByEmailWipesMessageContent(): void
    {
        $this->mockSelect([5]);

        $capturedUpdates = [];
        $this->connection->method('beginTransaction');
        $this->connection->method('commit');
        $this->connection->method('update')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedUpdates) {
                $capturedUpdates[$table] = $data;
            });
        $this->connection->method('delete');

        $this->service->anonymizeByEmail('x@y.com');

        $msgUpdate = $capturedUpdates['cc_conversation_message'] ?? [];
        $this->assertStringContainsString('DSGVO', $msgUpdate['content_text'] ?? '');
        $this->assertNull($msgUpdate['content_html'] ?? 'NOT_NULL');
        $this->assertNull($msgUpdate['intent_data'] ?? 'NOT_NULL');
    }

    public function testAnonymizeByEmailRollsBackOnException(): void
    {
        $this->mockSelect([7]);

        $this->connection->method('beginTransaction');
        $this->connection->method('update')->willThrowException(new \RuntimeException('DB broken'));
        $this->connection->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->service->anonymizeByEmail('fail@example.com');
    }

    public function testAnonymizeByEmailReturnsFalseForEmptyString(): void
    {
        $count = $this->service->anonymizeByEmail('');
        $this->assertSame(0, $count);
    }

    public function testAnonymizeByConversationIdReturnsFalseWhenNotFound(): void
    {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        $result = $this->service->anonymizeByConversationId(999);

        $this->assertFalse($result);
    }

    public function testAnonymizeByConversationIdReturnsTrueWhenFound(): void
    {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'id'             => 42,
            'customer_email' => 'test@example.com',
            'status'         => 'open',
        ]);
        $this->connection->method('beginTransaction');
        $this->connection->method('commit');
        $this->connection->expects($this->exactly(3))->method('update');

        $result = $this->service->anonymizeByConversationId(42);

        $this->assertTrue($result);
    }
}
