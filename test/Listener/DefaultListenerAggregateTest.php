<?php
/**
 * @link      http://github.com/zendframework/zend-modulemanager for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-modulemanager/blob/master/LICENSE.md New BSD License
 */
declare(strict_types=1);

namespace ZendTest\ModuleManager\Listener;

use Zend\EventManager\Test\EventListenerIntrospectionTrait;
use Zend\ModuleManager\Listener\ListenerOptions;
use Zend\ModuleManager\Listener\DefaultListenerAggregate;
use Zend\ModuleManager\ModuleManager;

/**
 * @covers Zend\ModuleManager\Listener\AbstractListener
 * @covers Zend\ModuleManager\Listener\DefaultListenerAggregate
 */
class DefaultListenerAggregateTest extends AbstractListenerTestCase
{
    use EventListenerIntrospectionTrait;

    /**
     * @var DefaultListenerAggregate
     */
    protected $defaultListeners;

    public function setUp()
    {
        $this->defaultListeners = new DefaultListenerAggregate(
            new ListenerOptions([])
        );
    }

    public function testDefaultListenerAggregateCanAttachItself()
    {
        $moduleManager = new ModuleManager(['ListenerTestModule']);
        (new DefaultListenerAggregate)->attach($moduleManager->getEventManager());

        $events = $this->getEventsFromEventManager($moduleManager->getEventManager());
        $expectedEvents = [
            'loadModules.init' => [
                'Closure', // for 'Zend\ModuleManager\ModuleManager
            ],
            'loadModules' => [
                'config-pre' => 'Zend\ModuleManager\Listener\ConfigListener',
                'config-post' => 'Zend\ModuleManager\Listener\ConfigListener',
                'Zend\ModuleManager\Listener\LocatorRegistrationListener',
                'Closure', // for 'Zend\ModuleManager\ModuleManager
            ],
            'loadModule.resolve' => [
                'Zend\ModuleManager\Listener\ModuleResolverListener',
            ],
            'loadModule.init' => [
                'Zend\ModuleManager\Listener\InitTrigger',
            ],
            'loadModule' => [
                'Zend\ModuleManager\Listener\ModuleDependencyCheckerListener',
                'Zend\ModuleManager\Listener\OnBootstrapListener',
                'Zend\ModuleManager\Listener\ConfigListener',
                'Zend\ModuleManager\Listener\LocatorRegistrationListener',
            ],
        ];
        foreach ($expectedEvents as $event => $expectedListeners) {
            $this->assertContains($event, $events);
            $count = 0;
            foreach ($this->getListenersForEvent($event, $moduleManager->getEventManager()) as $listener) {
                if (is_array($listener)) {
                    $listener = $listener[0];
                }
                $listenerClass = get_class($listener);
                $this->assertContains($listenerClass, $expectedListeners);
                $count += 1;
            }

            $this->assertSame(count($expectedListeners), $count);
        }
    }

    public function testDefaultListenerAggregateCanDetachItself()
    {
        $listenerAggregate = new DefaultListenerAggregate;
        $moduleManager     = new ModuleManager(['ListenerTestModule']);
        $events            = $moduleManager->getEventManager();

        $this->assertEquals(2, count($this->getEventsFromEventManager($events)));

        $listenerAggregate->attach($events);
        $this->assertEquals(6, count($this->getEventsFromEventManager($events)));

        $listenerAggregate->detach($events);
        $this->assertEquals(2, count($this->getEventsFromEventManager($events)));
    }
}
