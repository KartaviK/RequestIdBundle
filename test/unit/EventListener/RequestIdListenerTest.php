<?php
/*
 * This file is part of chrisguitarguy/request-id-bundle

 * Copyright (c) Christopher Davis <http://christopherdavis.me>
 *
 * For full copyright information see the LICENSE file distributed
 * with this source code.
 *
 * @license     http://opensource.org/licenses/MIT MIT
 */

namespace Chrisguitarguy\RequestId\EventListener;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as Symfony43Dispatcher;
use Chrisguitarguy\RequestId\RequestIdGenerator;
use Chrisguitarguy\RequestId\RequestIdStorage;
use Chrisguitarguy\RequestId\UnitTestCase;

class RequestIdListenerTest extends UnitTestCase
{
    const REQUEST_HEADER = 'Request-Id';
    const RESPONSE_HEADER = 'Response-Id';

    private $idStorage, $idGen, $listener, $dispatcher, $request, $response, $kernel;

    public function testNonMasterRequestsDoNothingOnRequest()
    {
        $event = new GetResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::SUB_REQUEST
        );
        $this->idStorage->expects($this->never())
            ->method('getRequestId');

        $this->dispatchEvent(KernelEvents::REQUEST, $event);
    }

    public function testListenerSetsTheRequestIdToStorageWhenFoundInRequestHeaders()
    {
        $this->request->headers->set(self::REQUEST_HEADER, 'testId');
        $this->willNotGenerate();
        $this->idStorage->expects($this->never())
            ->method('getRequestId');
        $this->idStorage->expects($this->once())
            ->method('setRequestId')
            ->with('testId');
        $event = new GetResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $this->dispatchEvent(KernelEvents::REQUEST, $event);
    }

    public function testListenerSetsTheIdOnRequestWhenItsFoundInStorage()
    {
        $this->willNotGenerate();
        $this->idStorage->expects($this->once())
            ->method('getRequestId')
            ->willReturn('abc123');
        $this->idStorage->expects($this->never())
            ->method('setRequestId');
        $event = new GetResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $this->dispatchEvent(KernelEvents::REQUEST, $event);

        $this->assertEquals('abc123', $this->request->headers->get(self::REQUEST_HEADER));
    }

    public function testListenerGenerateNewIdAndSetsItOnRequestAndStorageWhenNoIdIsFound()
    {
        $this->idGen->expects($this->once())
            ->method('generate')
            ->willReturn('def234');
        $this->idStorage->expects($this->once())
            ->method('getRequestId')
            ->willReturn(null);
        $this->idStorage->expects($this->once())
            ->method('setRequestId')
            ->with('def234');
        $event = new GetResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $this->dispatchEvent(KernelEvents::REQUEST, $event);

        $this->assertEquals('def234', $this->request->headers->get(self::REQUEST_HEADER));
    }

    public function testListenerIgnoresIncomingRequestHeadersWhenTrustRequestIsFalse()
    {
        $this->dispatcher->removeSubscriber($this->listener);
        $this->dispatcher->addSubscriber(new RequestIdListener(
            self::REQUEST_HEADER,
            self::REQUEST_HEADER,
            false,
            $this->idStorage,
            $this->idGen
        ));
        $this->idGen->expects($this->once())
            ->method('generate')
            ->willReturn('def234');
        $this->idStorage->expects($this->once())
            ->method('getRequestId')
            ->willReturn(null);
        $this->idStorage->expects($this->once())
            ->method('setRequestId')
            ->with('def234');
        $this->request->headers->set(self::REQUEST_HEADER, 'abc123');
        $event = new GetResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST
        );

        $this->dispatchEvent(KernelEvents::REQUEST, $event);

        $this->assertEquals('def234', $this->request->headers->get(self::REQUEST_HEADER));
    }

    public function testListenerDoesNothingToResponseWithoutMasterRequest()
    {
        $this->idStorage->expects($this->never())
            ->method('getRequestId');

        $this->dispatchEvent(KernelEvents::RESPONSE, new FilterResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::SUB_REQUEST,
            $this->response
        ));

        $this->assertFalse($this->response->headers->has(self::REQUEST_HEADER));
    }

    public function testRequestWithoutIdInStorageDoesNotSetHeaderOnResponse()
    {
        $this->idStorage->expects($this->once())
            ->method('getRequestId')
            ->willReturn(null);

        $this->dispatchEvent(KernelEvents::RESPONSE, new FilterResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST,
            $this->response
        ));

        $this->assertFalse($this->response->headers->has(self::REQUEST_HEADER));
    }

    public function testRequestWithIdInStorageSetsIdOnResponse()
    {
        $this->idStorage->expects($this->once())
            ->method('getRequestId')
            ->willReturn('ghi345');

        $this->dispatchEvent(KernelEvents::RESPONSE, new FilterResponseEvent(
            $this->kernel,
            $this->request,
            HttpKernelInterface::MASTER_REQUEST,
            $this->response
        ));

        $this->assertEquals('ghi345', $this->response->headers->get(self::RESPONSE_HEADER));
    }

    protected function setUp()
    {
        $this->idStorage = $this->createMock(RequestIdStorage::class);
        $this->idGen = $this->createMock(RequestIdGenerator::class);
        $this->listener = new RequestIdListener(
            self::REQUEST_HEADER,
            self::RESPONSE_HEADER,
            true,
            $this->idStorage,
            $this->idGen
        );
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber($this->listener);
        $this->request = Request::create('/');
        $this->response = new Response('Hello, World');
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    private function willNotGenerate()
    {
        $this->idGen->expects($this->never())
            ->method('generate');
    }

    private function dispatchEvent(string $eventName, Event $event) : void
    {
        if ($this->dispatcher instanceof Symfony43Dispatcher) {
            $this->dispatcher->dispatch($event, $eventName);
        } else {
            $this->dispatcher->dispatch($eventName, $event);
        }
    }
}
