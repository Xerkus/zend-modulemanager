<?php
/**
 * @link      http://github.com/zendframework/zend-modulemanager for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-modulemanager/blob/master/LICENSE.md New BSD License
 */
declare(strict_types=1);

namespace Zend\ModuleManager;

use Closure;
use Traversable;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\ModuleManager\Exception\RuntimeException;

/**
 * Module manager
 */
class ModuleManager implements ModuleManagerInterface
{
    /**#@+
     * Reference to Zend\Mvc\MvcEvent::EVENT_BOOTSTRAP
     */
    const EVENT_BOOTSTRAP = 'bootstrap';
    /**#@-*/

    /**
     * @var array An array of Module classes of loaded modules
     */
    protected $loadedModules = [];

    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @var ModuleEvent
     */
    protected $event;

    /**
     * @var int
     */
    protected $loadFinished;

    /**
     * modules
     *
     * @var array|Traversable
     */
    protected $modules = [];

    /**
     * True if modules have already been loaded
     *
     * @var bool
     */
    protected $modulesAreLoaded = false;

    /**
     * True if modules have already been initialized
     *
     * @var bool
     */
    protected $modulesAreInitialized = false;

    /**
     * Constructor
     *
     * @param  array|Traversable $modules
     * @param  EventManagerInterface $eventManager
     */
    public function __construct($modules, EventManagerInterface $eventManager = null)
    {
        $this->setModules($modules);
        if ($eventManager instanceof EventManagerInterface) {
            $this->setEventManager($eventManager);
        }
    }

    /**
     * Load the provided modules.
     *
     * @triggers loadModules
     * @triggers loadModules.post
     * @return   ModuleManager
     */
    public function loadModules()
    {
        if (true === $this->modulesAreLoaded) {
            return $this;
        }

        $events = $this->getEventManager();
        $event  = $this->getEvent();

        /**
         * Separate init event to allow modules to register listeners for later stages
         */
        $event->setName(ModuleEvent::EVENT_LOAD_MODULES_INIT);
        $events->triggerEvent($event);

        $event->setName(ModuleEvent::EVENT_LOAD_MODULES);
        $events->triggerEvent($event);

        /**
         * Having a dedicated .post event abstracts the complexity of priorities from the user.
         * Users can attach to the .post event and be sure that important
         * things like config merging are complete without having to worry if
         * they set a low enough priority.
         */
        $event->setName(ModuleEvent::EVENT_LOAD_MODULES_POST);
        $events->triggerEvent($event);

        return $this;
    }

    /**
     * Load a specific module by name.
     *
     * @param  string|array $module
     * @throws Exception\RuntimeException
     * @triggers loadModule.resolve
     * @triggers loadModule.init
     * @return mixed Module's Module class
     */
    public function loadModule($module)
    {
        $moduleName = $module;
        if (is_array($module)) {
            $moduleName = key($module);
            $module = current($module);
        }

        if (isset($this->loadedModules[$moduleName])) {
            return $this->loadedModules[$moduleName];
        }
        if (true === $this->modulesAreInitialized) {
            throw new RuntimeException('Cannot load module, all modules are already initialized');
        }

        return $this->initModule($moduleName, $module);
    }

    /**
     * Get an array of the loaded modules.
     *
     * @param  bool  $loadModules If true, load modules if they're not already
     * @return array An array of Module objects, keyed by module name
     */
    public function getLoadedModules($loadModules = false)
    {
        if (true === $loadModules) {
            $this->loadModules();
        }

        return $this->loadedModules;
    }

    /**
     * Get an instance of a module class by the module name
     *
     * @param  string $moduleName
     * @return mixed
     */
    public function getModule($moduleName)
    {
        if (! isset($this->loadedModules[$moduleName])) {
            return;
        }
        return $this->loadedModules[$moduleName];
    }

    /**
     * Get the array of module names that this manager should load.
     *
     * @return array
     */
    public function getModules()
    {
        return $this->modules;
    }

    /**
     * Set an array or Traversable of module names that this module manager should load.
     *
     * @param  mixed $modules array or Traversable of module names
     * @throws Exception\InvalidArgumentException
     * @return ModuleManager
     */
    public function setModules($modules)
    {
        if (is_array($modules) || $modules instanceof Traversable) {
            $this->modules = $modules;
        } else {
            throw new Exception\InvalidArgumentException(
                sprintf(
                    'Parameter to %s\'s %s method must be an array or implement the Traversable interface',
                    __CLASS__,
                    __METHOD__
                )
            );
        }
        return $this;
    }

    /**
     * Get the module event
     *
     * @return ModuleEvent
     */
    public function getEvent()
    {
        if (! $this->event instanceof ModuleEvent) {
            $this->setEvent(new ModuleEvent());
        }
        return $this->event;
    }

    /**
     * Set the module event
     *
     * @param  ModuleEvent $event
     * @return ModuleManager
     */
    public function setEvent(ModuleEvent $event)
    {
        $event->setTarget($this);
        $this->event = $event;
        return $this;
    }

    /**
     * Set the event manager instance used by this module manager.
     *
     * @param  EventManagerInterface $events
     * @return ModuleManager
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers([
            __CLASS__,
            get_class($this),
            'module_manager',
        ]);
        $this->events = $events;
        $this->attachDefaultListeners($events);
        return $this;
    }

    /**
     * Retrieve the event manager
     *
     * Lazy-loads an EventManager instance if none registered.
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (! $this->events instanceof EventManagerInterface) {
            $this->setEventManager(new EventManager());
        }
        return $this->events;
    }

    /**
     * Handle the loadModules.init event
     *
     * @return void
     */
    protected function onModulesInit()
    {
        if (true === $this->modulesAreLoaded || true === $this->modulesAreInitialized) {
            return;
        }

        foreach ($this->getModules() as $moduleName => $module) {
            if (! \is_string($moduleName) || \is_numeric($moduleName)) {
                $moduleName = $module;
            }
            if (! is_string($moduleName)) {
                throw new Exception\RuntimeException(sprintf(
                    'Module (%s) must have a key identifier.',
                    (is_object($module) ? get_class($module) : gettype($module))
                ));
            }

            $this->initModule($moduleName, $module);
        }

        $this->modulesAreInitialized = true;
    }

    /**
     * Handle the loadModules event
     *
     * @return void
     */
    protected function onLoadModules()
    {
        if (true === $this->modulesAreLoaded) {
            return;
        }

        foreach ($this->loadedModules as $moduleName => $module) {
            $this->doLoadModule($moduleName, $module);
        }

        $this->modulesAreLoaded = true;
    }

    /**
     * Resolve and initialize module
     *
     * @param  string|array $module
     * @throws Exception\RuntimeException
     * @triggers loadModule.resolve
     * @return mixed Module's Module class
     */
    protected function initModule(string $moduleName, $module)
    {
        if (isset($this->loadedModules[$moduleName])) {
            return $this->loadedModules[$moduleName];
        }

        /*
         * Keep track of nested module loading using the $loadFinished
         * property.
         *
         * Increment the value for each loadModule() call and then decrement
         * once the loading process is complete.
         *
         * To load a module, we clone the event if we are inside a nested
         * loadModule() call, and use the original event otherwise.
         */
        if (! isset($this->loadFinished)) {
            $this->loadFinished = 0;
        }

        $event = ($this->loadFinished > 0) ? clone $this->getEvent() : $this->getEvent();
        $event->setModuleName($moduleName);

        $this->loadFinished++;

        if (! is_object($module)) {
            $module = $this->loadModuleByName($event);
        }
        $event->setModule($module);
        $event->setName(ModuleEvent::EVENT_LOAD_MODULE_INIT);

        $this->loadedModules[$moduleName] = $module;
        $this->getEventManager()->triggerEvent($event);

        $this->loadFinished--;

        return $module;
    }

    /**
     * Actually load module
     *
     * @param string $moduleName
     * @param  object $module
     * @triggers loadModule
     */
    protected function doLoadModule(string $moduleName, $module) : void
    {
        $event = $this->getEvent();
        $event->setModuleName($moduleName);
        $event->setModule($module);
        $event->setName(ModuleEvent::EVENT_LOAD_MODULE);

        $this->getEventManager()->triggerEvent($event);
    }

    /**
     * Load a module with the name
     * @param  ModuleEvent $event
     * @return mixed                            module instance
     * @throws Exception\RuntimeException
     */
    protected function loadModuleByName(ModuleEvent $event)
    {
        $event->setName(ModuleEvent::EVENT_LOAD_MODULE_RESOLVE);
        $result = $this->getEventManager()->triggerEventUntil(function ($r) {
            return (is_object($r));
        }, $event);

        $module = $result->last();
        if (! is_object($module)) {
            throw new Exception\RuntimeException(sprintf(
                'Module (%s) could not be initialized.',
                $event->getModuleName()
            ));
        }

        return $module;
    }

    /**
     * Register the default event listeners
     *
     * @param EventManagerInterface $events
     * @return ModuleManager
     */
    protected function attachDefaultListeners($events)
    {
        $events->attach(ModuleEvent::EVENT_LOAD_MODULES_INIT, Closure::fromCallable([$this, 'onModulesInit']));
        $events->attach(ModuleEvent::EVENT_LOAD_MODULES, Closure::fromCallable([$this, 'onLoadModules']));
    }
}
