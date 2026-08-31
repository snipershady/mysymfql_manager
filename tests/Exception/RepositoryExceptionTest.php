<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\RepositoryException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RepositoryException}.
 */
final class RepositoryExceptionTest extends TestCase
{
    public function testItIsAThrowable(): void
    {
        $this->assertInstanceOf(\Exception::class, new RepositoryException());
    }

    public function testMessageAndCodeAreKeptWhenThereIsNoPreviousException(): void
    {
        $exception = new RepositoryException('query failed', 42);

        $this->assertSame('query failed', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function testPreviousExceptionMessageIsAppended(): void
    {
        $previous = new \RuntimeException('connection reset');
        $exception = new RepositoryException('query failed', 7, $previous);

        $this->assertSame('query failed, caller message = connection reset', $exception->getMessage());
        $this->assertSame(7, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testDefaultsProduceAnEmptyMessageAndZeroCode(): void
    {
        $exception = new RepositoryException();

        $this->assertSame('', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testItCanBeCaughtAsAStandardException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('boom, caller message = root cause');

        throw new RepositoryException('boom', 0, new \LogicException('root cause'));
    }
}
