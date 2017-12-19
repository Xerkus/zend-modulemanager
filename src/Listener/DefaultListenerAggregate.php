<?php
/**
 * @link      http://github.com/zendframework/zend-modulemanager for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-modulemanager/blob/master/LICENSE.md New BSD License
 */
declare(strict_types=1);

namespace Zend\ModuleManager\Listener;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\ModuleManager\ModuleEvent;

/**
 * Default listener aggregate
 */
class DefaultListenerAggregate extends AbstractListener implements
    ListenerAggregateInterface
{
    /**
     * @var array
     */
    protected $listeners = [];

    /**
     * @var ConfigMergerInterface
     */
    protected $configListener;

    /**
     * Attach one or more listeners
     *
     * @param  EventManagerInterface $events
     * @param  int $priority
     * @return DefaultListenerAggregate
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $options                     = $this->getOptions();
        $configListener              = $this->getConfigListener();
        $locatorRegistrationListener = new LocatorRegistrationListener($options);

        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE_RESOLVE, new ModuleResolverListener);

        if ($options->getCheckDependencies()) {
            $this->listeners[] = $events->attach(
                ModuleEvent::EVENT_LOAD_MODULE,
                new ModuleDependencyCheckerListener,
                8000
            );
        }

        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE_INIT, new InitTrigger($options));
        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, new OnBootstrapListener($options));

        $locatorRegistrationListener->attach($events);
        $configListener->attach($events);
        $this->listeners[] = $locatorRegistrationListener;
        $this->listeners[] = $configListener;
        return $this;
    }

    /**
     * Detach all previously attached listeners
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $key => $listener) {
            if ($listener instanceof ListenerAggregateInterface) {
                $listener->detach($events);
                unset($this->listeners[$key]);
                continue;
            }

            $events->detach($listener);
            unset($this->listeners[$key]);
        }
    }

    /**
     * Get the config merger.
     *
     * @return ConfigMergerInterface
     */
    public function getConfigListener()
    {
        if (! $this->configListener instanceof ConfigMergerInterface) {
            $this->setConfigListener(new ConfigListener($this->getOptions()));
        }
        return $this->configListener;
    }

    /**
     * Set the config merger to use.
     *
     * @param  ConfigMergerInterface $configListener
     * @return DefaultListenerAggregate
     */
    public function setConfigListener(ConfigMergerInterface $configListener)
    {
        $this->configListener = $configListener;
        return $this;
    }
}
