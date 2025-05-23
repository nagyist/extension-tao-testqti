<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2016-2023 (original work) Open Assessment Technologies SA.
 */

namespace oat\taoQtiTest\models\runner;

use common_Exception;
use common_exception_Error;
use common_exception_InconsistentData;
use common_exception_InvalidArgumentType as InvalidArgumentTypeException;
use common_exception_NotImplemented;
use common_ext_ExtensionException;
use common_ext_ExtensionsManager;
use common_Logger;
use common_persistence_AdvKeyValuePersistence;
use common_persistence_KeyValuePersistence;
use common_session_SessionManager;
use Exception;
use oat\libCat\result\ItemResult;
use oat\libCat\result\ResultVariable;
use oat\oatbox\event\EventManager;
use oat\oatbox\filesystem\FilesystemException;
use oat\oatbox\service\ConfigurableService;
use oat\oatbox\service\exception\InvalidServiceManagerException;
use oat\tao\model\featureFlag\FeatureFlagChecker;
use oat\tao\model\featureFlag\FeatureFlagCheckerInterface;
use oat\tao\model\theme\ThemeService;
use oat\taoDelivery\model\execution\Delete\DeliveryExecutionDeleteRequest;
use oat\taoDelivery\model\execution\DeliveryExecution;
use oat\taoDelivery\model\execution\DeliveryServerService;
use oat\taoDelivery\model\execution\ServiceProxy as TaoDeliveryServiceProxy;
use oat\taoDelivery\model\RuntimeService;
use oat\taoOutcomeRds\model\RdsResultStorage;
use oat\taoQtiItem\model\portableElement\exception\PortableElementNotFoundException;
use oat\taoQtiItem\model\portableElement\exception\PortableModelMissing;
use oat\taoQtiItem\model\portableElement\PortableElementService;
use oat\taoQtiItem\model\QtiJsonItemCompiler;
use oat\taoQtiTest\models\cat\CatService;
use oat\taoQtiTest\models\cat\GetDeliveryExecutionsItems;
use oat\taoQtiTest\models\event\AfterAssessmentTestSessionClosedEvent;
use oat\taoQtiTest\models\event\QtiContinueInteractionEvent;
use oat\taoQtiTest\models\event\TestExitEvent;
use oat\taoQtiTest\models\event\TestInitEvent;
use oat\taoQtiTest\models\event\TestTimeoutEvent;
use oat\taoQtiTest\models\event\DeliveryExecutionFinish;
use oat\taoQtiTest\models\ExtendedStateService;
use oat\taoQtiTest\models\files\QtiFlysystemFileManager;
use oat\taoQtiTest\models\render\UpdateItemContentReferencesService;
use oat\taoQtiTest\models\runner\config\QtiRunnerConfig;
use oat\taoQtiTest\models\runner\config\RunnerConfig;
use oat\taoQtiTest\models\runner\map\QtiRunnerMap;
use oat\taoQtiTest\models\runner\navigation\QtiRunnerNavigation;
use oat\taoQtiTest\models\runner\rubric\QtiRunnerRubric;
use oat\taoQtiTest\models\runner\session\TestSession;
use oat\taoQtiTest\models\runner\toolsStates\ToolsStateStorage;
use oat\taoQtiTest\models\TestSessionService;
use oat\taoResultServer\models\classes\implementation\ResultServerService;
use qtism\common\datatypes\QtiInteger;
use qtism\common\datatypes\QtiString as QtismString;
use qtism\common\enums\BaseType;
use qtism\common\enums\Cardinality;
use qtism\data\AssessmentItemRef;
use qtism\data\NavigationMode;
use qtism\data\storage\php\PhpStorageException;
use qtism\data\SubmissionMode;
use qtism\runtime\common\ResponseVariable;
use qtism\runtime\common\State;
use qtism\runtime\common\Utils;
use qtism\runtime\tests\AssessmentItemSession;
use qtism\runtime\tests\AssessmentItemSessionException;
use qtism\runtime\tests\AssessmentItemSessionState;
use qtism\runtime\tests\AssessmentTestSession;
use qtism\runtime\tests\AssessmentTestSessionException;
use qtism\runtime\tests\AssessmentTestSessionState;
use qtism\runtime\tests\RouteItem;
use qtism\runtime\tests\SessionManager;
use tao_models_classes_FileNotFoundException;
use tao_models_classes_service_StateStorage;
use taoQtiCommon_helpers_PciStateOutput;
use taoQtiCommon_helpers_PciVariableFiller;
use taoQtiCommon_helpers_ResultTransmissionException;
use taoQtiCommon_helpers_ResultTransmitter;
use taoQtiTest_helpers_TestRunnerUtils as TestRunnerUtils;
use taoResultServer_models_classes_TraceVariable;
use taoResultServer_models_classes_Variable;

/**
 * Class QtiRunnerService
 *
 * QTI implementation service for the test runner
 *
 * @package oat\taoQtiTest\models
 * @author Jean-Sébastien Conan <jean-sebastien.conan@vesperiagroup.com>
 */
class QtiRunnerService extends ConfigurableService implements PersistableRunnerServiceInterface, RunnerService
{
    public const SERVICE_ID = 'taoQtiTest/QtiRunnerService';

    /**
     * @deprecated use SERVICE_ID
     */
    public const CONFIG_ID = self::SERVICE_ID;

    public const TOOL_ITEM_THEME_SWITCHER     = 'itemThemeSwitcher';
    public const TOOL_ITEM_THEME_SWITCHER_KEY = 'taoQtiTest/runner/plugins/tools/itemThemeSwitcher/itemThemeSwitcher';

    private const TIMEOUT_EXCEPTION_CODES = [
        AssessmentTestSessionException::ASSESSMENT_TEST_DURATION_OVERFLOW,
        AssessmentTestSessionException::TEST_PART_DURATION_OVERFLOW,
        AssessmentTestSessionException::ASSESSMENT_SECTION_DURATION_OVERFLOW,
        AssessmentTestSessionException::ASSESSMENT_ITEM_DURATION_OVERFLOW,
    ];

    /**
     * The test runner config
     */
    protected ?RunnerConfig $testConfig = null;

    /**
     * Use to store retrieved item data, inside the same request
     */
    private array $dataCache = [];

    /**
     * Get the data folder from a given item definition
     * @param string $itemRef - formatted as itemURI|publicFolderURI|privateFolderURI
     * @return array the path
     * @throws common_Exception
     */
    private function loadItemData($itemRef, $path)
    {
        $cacheKey = $itemRef . $path;
        if (! empty($cacheKey) && isset($this->dataCache[$itemRef . $path])) {
            return $this->dataCache[$itemRef . $path];
        }

        $directoryIds = explode('|', $itemRef);
        if (count($directoryIds) < 3) {
            if (is_scalar($itemRef)) {
                $itemRefInfo = gettype($itemRef) . ': ' . strval($itemRef);
            } elseif (is_object($itemRef)) {
                $itemRefInfo = gettype($itemRef) . ': ' . get_class($itemRef);
            } else {
                $itemRefInfo = gettype($itemRef);
            }

            throw new common_exception_InconsistentData(
                "The itemRef (value = '${itemRefInfo}') is not formatted correctly."
            );
        }

        $itemUri = $directoryIds[0];
        $userDataLang = common_session_SessionManager::getSession()->getDataLanguage();
        $directory = \tao_models_classes_service_FileStorage::singleton()->getDirectoryById($directoryIds[2]);

        if ($directory->has($userDataLang)) {
            $lang = $userDataLang;
        } elseif ($directory->has(DEFAULT_LANG)) {
            common_Logger::d(
                $userDataLang . ' is not part of compilation directory for item : ' . $itemUri . ' use ' . DEFAULT_LANG
            );
            $lang = DEFAULT_LANG;
        } else {
            throw new common_Exception(
                'item : ' . $itemUri . 'is neither compiled in ' . $userDataLang . ' nor in ' . DEFAULT_LANG
            );
        }
        try {
            $content = $directory->read($lang . DIRECTORY_SEPARATOR . $path);
            $jsonContent = $this->getUpdateItemContentReferencesService()->__invoke(json_decode($content, true));

            $this->dataCache[$cacheKey] = $jsonContent;
            return $this->dataCache[$cacheKey];
        } catch (FilesystemException $e) {
            throw new tao_models_classes_FileNotFoundException(
                $path . ' for item reference ' . $itemRef
            );
        }
    }

    /**
     * Gets the test session for a particular delivery execution
     *
     * This method is called before each action (moveNext, moveBack, pause, ...) call.
     *
     * @param string $testDefinitionUri The URI of the test
     * @param string $testCompilationUri The URI of the compiled delivery
     * @param string $testExecutionUri The URI of the delivery execution
     * @param string $userUri User identifier. If null current user will be used
     * @return QtiRunnerServiceContext
     * @throws common_Exception
     */
    public function getServiceContext($testDefinitionUri, $testCompilationUri, $testExecutionUri, $userUri = null)
    {
        // create a service context based on the provided URI
        // initialize the test session and related objects
        $serviceContext = new QtiRunnerServiceContext($testDefinitionUri, $testCompilationUri, $testExecutionUri);
        $this->propagate($serviceContext);
        $serviceContext->setTestConfig($this->getTestConfig());
        $serviceContext->setUserUri($userUri);

        $sessionService = $this->getServiceManager()->get(TestSessionService::SERVICE_ID);
        $sessionService->registerTestSession(
            $serviceContext->getTestSession(),
            $serviceContext->getStorage(),
            $serviceContext->getCompilationDirectory()
        );

        return $serviceContext;
    }

    /**
     * Checks the created context, then initializes it.
     * @param RunnerServiceContext $context
     * @return RunnerServiceContext
     * @throws common_Exception
     */
    public function initServiceContext(RunnerServiceContext $context)
    {
        // will throw exception if the test session is not valid
        $this->check($context);

        // starts the context
        $context->init();

        return $context;
    }

    /**
     * Persists the AssessmentTestSession into binary data.
     * @param QtiRunnerServiceContext $serviceContext
     */
    public function persist(RunnerServiceContext $serviceContext): void
    {
        $testSession = $serviceContext->getTestSession();
        $sessionId = $testSession->getSessionId();

        $this->getLogger()->debug("Persisting QTI Assessment Test Session '${sessionId}'...");
        $serviceContext->getStorage()->persist($testSession);
        if ($this->isTerminated($serviceContext)) {
            /** @var StorageManager $storageManager */
            $storageManager = $this->getServiceManager()->get(StorageManager::SERVICE_ID);
            $storageManager->persist();

            $userId = common_session_SessionManager::getSession()->getUser()->getIdentifier();
            $eventManager = $this->getServiceManager()->get(EventManager::SERVICE_ID);
            $eventManager->trigger(new AfterAssessmentTestSessionClosedEvent($testSession, $userId));
        }
    }

    /**
     * Initializes the delivery execution session
     *
     * This method is called whenever a candidate enters the test. This includes
     *
     * * Newly launched/instantiated test session.
     * * The candidate refreshes the client (F5).
     * * Resumed test sessions.
     *
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws common_Exception
     */
    public function init(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'init');

        /* @var TestSession $session */
        $session = $context->getTestSession();

        // code borrowed from the previous implementation, but the reset timers option has been discarded
        if ($session->getState() === AssessmentTestSessionState::INITIAL) {
            // The test has just been instantiated.
            $session->beginTestSession();
            $event = new TestInitEvent($session);
            $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger($event);

            $this->getLogger()->info(
                sprintf('Assessment Test Session begun. Session id: %s', $session->getSessionId())
            );

            if ($context->isAdaptive()) {
                $this->getLogger()->debug("Very first item is adaptive.");
                $nextCatItemId = $context->selectAdaptiveNextItem();
                $context->persistCurrentCatItemId($nextCatItemId);
                $context->persistSeenCatItemIds($nextCatItemId);
            }
        } elseif ($session->getState() === AssessmentTestSessionState::SUSPENDED) {
            $session->resume();
        }

        $session->initItemTimer();
        if ($session->isTimeout() === false) {
            TestRunnerUtils::beginCandidateInteraction($session);
        }

        $this->getServiceManager()->get(ExtendedStateService::SERVICE_ID)->clearEvents($session->getSessionId());

        return true;
    }

    /**
     * Gets the test runner config
     * @return RunnerConfig
     * @throws common_ext_ExtensionException
     */
    public function getTestConfig()
    {
        if ($this->testConfig === null) {
            $this->testConfig = $this->getServiceManager()->get(QtiRunnerConfig::SERVICE_ID);
        }

        return $this->testConfig;
    }

    /**
     * Gets the test definition data
     *
     * @deprecated the testData is not necessary anymore
     * if the config is given directly to the test runner configuration
     *
     * @param RunnerServiceContext $context
     * @return array
     * @throws common_Exception
     */
    public function getTestData(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getTestData');

        $testDefinition = $context->getTestDefinition();

        $response['title'] = $testDefinition->getTitle();
        $response['identifier'] = $testDefinition->getIdentifier();
        $response['className'] = $testDefinition->getQtiClassName();
        $response['toolName'] = $testDefinition->getToolName();
        $response['exclusivelyLinear'] = $testDefinition->isExclusivelyLinear();
        $response['hasTimeLimits'] = $testDefinition->hasTimeLimits();

        //states that can be found in the context
        $response['states'] = [
            'initial'       => AssessmentTestSessionState::INITIAL,
            'interacting'   => AssessmentTestSessionState::INTERACTING,
            'modalFeedback' => AssessmentTestSessionState::MODAL_FEEDBACK,
            'suspended'     => AssessmentTestSessionState::SUSPENDED,
            'closed'        => AssessmentTestSessionState::CLOSED
        ];

        $response['itemStates'] = [
            'initial'       => AssessmentItemSessionState::INITIAL,
            'interacting'   => AssessmentItemSessionState::INTERACTING,
            'modalFeedback' => AssessmentItemSessionState::MODAL_FEEDBACK,
            'suspended'     => AssessmentItemSessionState::SUSPENDED,
            'closed'        => AssessmentItemSessionState::CLOSED,
            'solution'      => AssessmentItemSessionState::SOLUTION,
            'review'        => AssessmentItemSessionState::REVIEW,
            'notSelected'   => AssessmentItemSessionState::NOT_SELECTED
        ];

        $timeLimits = $testDefinition->getTimeLimits();
        if ($timeLimits) {
            if ($timeLimits->hasMinTime()) {
                $response['timeLimits']['minTime'] = [
                    'duration' => TestRunnerUtils::getDurationWithMicroseconds($timeLimits->getMinTime()),
                    'iso' => $timeLimits->getMinTime()->__toString(),
                ];
            }

            if ($timeLimits->hasMaxTime()) {
                $response['timeLimits']['maxTime'] = [
                    'duration' => TestRunnerUtils::getDurationWithMicroseconds($timeLimits->getMaxTime()),
                    'iso' => $timeLimits->getMaxTime()->__toString(),
                ];
            }
        }

        $response['config'] = $this->getTestConfig()->getConfig();

        if ($this->isThemeSwitcherEnabled()) {
            $themeSwitcherPlugin = [
                self::TOOL_ITEM_THEME_SWITCHER => [
                    "activeNamespace" => $this->getCurrentThemeId(),
                ],
            ];

            $response["config"]["plugins"] = array_merge($response["config"]["plugins"], $themeSwitcherPlugin);
        }

        return $response;
    }

    /**
     * Gets the test context object
     * @param RunnerServiceContext $context
     * @return array
     * @throws common_Exception
     */
    public function getTestContext(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getTestContext');

        /* @var TestSession $session */
        $session = $context->getTestSession();

        // The state of the test session.
        $response['state'] = $session->getState();

        // Default values for the test session context.
        $response['navigationMode'] = null;
        $response['submissionMode'] = null;
        $response['remainingAttempts'] = 0;
        $response['isAdaptive'] = false;

        // Context of interacting test
        if ($session->getState() === AssessmentTestSessionState::INTERACTING) {
            $config = $this->getTestConfig();
            $route = $session->getRoute();
            $currentItem = $route->current();
            $itemSession = $session->getCurrentAssessmentItemSession();
            $itemRef = $context->getCurrentAssessmentItemRef();

            $reviewConfig = $config->getConfigValue('review');
            $displaySubsectionTitle = isset($reviewConfig['displaySubsectionTitle'])
                ? (bool) $reviewConfig['displaySubsectionTitle']
                : true;
            $partiallyAnsweredIsAnswered = isset($reviewConfig['partiallyAnsweredIsAnswered'])
                ? (bool) $reviewConfig['partiallyAnsweredIsAnswered']
                : true;

            if ($displaySubsectionTitle) {
                $currentSection = $session->getCurrentAssessmentSection();
            } else {
                $sections = $currentItem->getAssessmentSections()->getArrayCopy();
                $currentSection = $sections[0];
            }

            $testOptions = $config->getTestOptions($context);

            // The navigation mode.
            $response['navigationMode'] = $session->getCurrentNavigationMode();
            $response['isLinear'] = $response['navigationMode'] == NavigationMode::LINEAR;

            // The submission mode.
            $response['submissionMode'] = $session->getCurrentSubmissionMode();

            // The number of remaining attempts for the current item.
            $response['remainingAttempts'] = $session->getCurrentRemainingAttempts();

            // Whether or not the current step is timed out.
            $response['isTimeout'] = $session->isTimeout();

            // The identifier of the current item.
            $response['itemIdentifier'] = $itemRef->getIdentifier();

            // The number of current attempt (1 for the first time ...)
            $response['attempt'] = ($context->isAdaptive())
                ? $context->getCatAttempts($response['itemIdentifier']) + 1
                : $itemSession['numAttempts']->getValue();

            // The state of the current AssessmentTestSession.
            $response['itemSessionState'] = $itemSession->getState();

            // Whether the current item is adaptive.
            $response['isAdaptive'] = $session->isCurrentAssessmentItemAdaptive();

            // Whether the current section is adaptive.
            $response['isCatAdaptive'] = $context->isAdaptive();

            // Whether the test map must be updated.
            // TODO: detect if the map need to be updated and set the flag
            $response['needMapUpdate'] = false;

            // Whether the current item is the very last one of the test.
            $response['isLast'] = (!$context->isAdaptive()) ? $route->isLast() : false;

            // The current position in the route.
            $response['itemPosition'] = $context->getCurrentPosition();

            // The current item flagged state
            $response['itemFlagged'] = TestRunnerUtils::getItemFlag($session, $response['itemPosition'], $context);

            // The current item answered state
            $response['itemAnswered'] = $this->isItemCompleted(
                $context,
                $currentItem,
                $itemSession,
                $partiallyAnsweredIsAnswered
            );

            // Time constraints.
            $response['timeConstraints'] = $this->buildTimeConstraints($context);

            // Test Part title.
            $response['testPartId'] = $session->getCurrentTestPart()->getIdentifier();

            // Current Section title.
            $response['sectionId'] = $currentSection->getIdentifier();
            $response['sectionTitle'] = $currentSection->getTitle();

            // Number of items composing the test session.
            $response['numberItems'] = $route->count();

            // Number of items completed during the test session.
            $response['numberCompleted'] = TestRunnerUtils::testCompletion($session);

            // Number of items presented during the test session.
            $response['numberPresented'] = $session->numberPresented();

            // Whether or not the progress of the test can be inferred.
            $response['considerProgress'] = TestRunnerUtils::considerProgress(
                $session,
                $context->getTestMeta(),
                $config->getConfig()
            );

            // Whether or not the deepest current section is visible.
            $response['isDeepestSectionVisible'] = $currentSection->isVisible();

            // If the candidate is allowed to move backward e.g. first item of the test.
            $response['canMoveBackward'] = $context->canMoveBackward();

            //Number of rubric blocks
            $response['numberRubrics'] = count($currentItem->getRubricBlockRefs());

            //add rubic blocks
            if ($response['numberRubrics'] > 0) {
                $response['rubrics'] = $this->getRubrics($context, $session->getCurrentAssessmentItemRef());
            }

            //prevent the user from submitting empty (i.e. default or null) responses, feature availability
            $response['enableAllowSkipping'] = $config->getConfigValue('enableAllowSkipping');

            //contextual value
            $response['allowSkipping'] = $testOptions['allowSkipping'];

            //prevent the user from submitting an invalid response
            $response['enableValidateResponses'] = $config->getConfigValue('enableValidateResponses');

            //contextual value
            $response['validateResponses'] = $testOptions['validateResponses'];

            //does the item has modal feedbacks ?
            $response['hasFeedbacks'] = $this->hasFeedbacks($context, $itemRef->getHref());

            // append dynamic options
            $response['options'] = $testOptions;
        }

        return $response;
    }

    /**
     * Gets the map of the test items
     * @param RunnerServiceContext $context
     * @param bool $partial the full testMap or only the current section
     * @return array
     * @throws common_Exception
     */
    public function getTestMap(RunnerServiceContext $context, $partial = false)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getTestMap');

        $mapService = $this->getServiceLocator()->get(QtiRunnerMap::SERVICE_ID);

        if ($partial) {
            return $mapService->getScopedMap($context, $this->getTestConfig());
        }

        return $mapService->getMap($context, $this->getTestConfig());
    }

    /**
     * Gets the rubrics related to the current session state.
     * @param RunnerServiceContext $context
     * @param AssessmentItemRef $itemRef (optional) otherwise use the current
     * @return mixed
     * @throws common_Exception
     */
    public function getRubrics(RunnerServiceContext $context, AssessmentItemRef $itemRef = null)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getRubrics');

        $rubricHelper = $this->getServiceLocator()->get(QtiRunnerRubric::SERVICE_ID);
        return $rubricHelper->getRubrics($context, $itemRef);
    }

    /**
     * Gets AssessmentItemRef's Href by AssessmentItemRef Identifier.
     * @param RunnerServiceContext $context
     * @param string $itemRef
     * @return string
     */
    public function getItemHref(RunnerServiceContext $context, string $itemRef): string
    {
        $mapService = $this->getServiceLocator()->get(QtiRunnerMap::SERVICE_ID);
        return (string)$mapService->getItemHref($context, $itemRef);
    }

    /**
     * Gets definition data of a particular item
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @return mixed
     * @throws common_Exception
     */
    public function getItemData(RunnerServiceContext $context, $itemRef)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getItemData');

        return $this->loadItemData($itemRef, QtiJsonItemCompiler::ITEM_FILE_NAME);
    }

    /**
     * Gets the state identifier for a particular item
     * @param QtiRunnerServiceContext $context
     * @param string $itemRef The item identifier
     * @return string The state identifier
     */
    protected function getStateId(QtiRunnerServiceContext $context, $itemRef)
    {
        return  $this->buildStorageItemKey($context->getTestExecutionUri(), $itemRef);
    }

    /**
     * @param string $deliveryExecutionUri
     * @param string $itemRef
     * @return string
     */
    private function buildStorageItemKey($deliveryExecutionUri, $itemRef)
    {
        return $deliveryExecutionUri . $itemRef;
    }

    /**
     * Gets the state of a particular item
     * @param RunnerServiceContext $context
     * @param string $itemRef
     * @return array|null
     * @throws common_Exception
     */
    public function getItemState(RunnerServiceContext $context, $itemRef)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getItemState');

        $serviceService = $this->getServiceManager()->get(StorageManager::SERVICE_ID);
        $userUri = common_session_SessionManager::getSession()->getUserUri();
        $stateId = $this->getStateId($context, $itemRef);
        $state = is_null($userUri) ? null : $serviceService->get($userUri, $stateId);

        if ($state) {
            $state = json_decode($state, true);
            if (is_null($state)) {
                throw new common_exception_InconsistentData('Unable to decode the state for the item ' . $itemRef);
            }
        }

        return $state;
    }

    /**
     * Sets the state of a particular item
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @param  $state
     * @return boolean
     * @throws common_Exception
     */
    public function setItemState(RunnerServiceContext $context, $itemRef, $state)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'setItemState');

        $serviceService = $this->getServiceManager()->get(StorageManager::SERVICE_ID);
        $userUri = common_session_SessionManager::getSession()->getUserUri();
        $stateId = $this->getStateId($context, $itemRef);
        if (!isset($state)) {
            $state = '';
        }

        return is_null($userUri) ? false : $serviceService->set($userUri, $stateId, json_encode($state));
    }

    /**
     * @param RunnerServiceContext $context
     * @param $toolStates
     * @throws InvalidServiceManagerException
     */
    public function setToolsStates(RunnerServiceContext $context, $toolStates)
    {
        if ($context instanceof QtiRunnerServiceContext && is_array($toolStates)) {
            /** @var ToolsStateStorage $toolsStateStorage */
            $toolsStateStorage = $this->getServiceLocator()->get(ToolsStateStorage::SERVICE_ID);

            $toolsStateStorage->storeStates($context->getTestExecutionUri(), $toolStates);
        }
    }

    /**
     * @param RunnerServiceContext $context
     * @return array
     * @throws InvalidServiceManagerException
     * @throws common_ext_ExtensionException
     */
    public function getToolsStates(RunnerServiceContext $context)
    {
        $toolsStates = [];

        // add those tools missing from the storage but presented on the config
        $toolsEnabled = $this->getTestConfig()->getConfigValue('toolStateServerStorage');

        if (count($toolsEnabled) === 0) {
            return [];
        }

        if ($context instanceof QtiRunnerServiceContext) {
            /** @var ToolsStateStorage $toolsStateStorage */
            $toolsStateStorage = $this->getServiceLocator()->get(ToolsStateStorage::SERVICE_ID);
            $toolsStates = $toolsStateStorage->getStates($context->getTestExecutionUri());
        }

        foreach ($toolsEnabled as $toolEnabled) {
            if (!array_key_exists($toolEnabled, $toolsStates)) {
                $toolsStates[$toolEnabled] = null;
            }
        }

        return $toolsStates;
    }

    /**
     * Parses the responses provided for a particular item
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @param $response
     * @return mixed
     * @throws common_Exception
     */
    public function parsesItemResponse(RunnerServiceContext $context, $itemRef, $response)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'storeItemResponse');

        /** @var TestSession $session */
        $session = $context->getTestSession();
        $currentItem  = $context->getCurrentAssessmentItemRef();
        $responses = new State();

        if ($currentItem === false) {
            $msg = "Trying to store item variables but the state of the test session is INITIAL or CLOSED.\n";
            $msg .= "Session state value: " . $session->getState() . "\n";
            $msg .= "Session ID: " . $session->getSessionId() . "\n";
            $msg .= "JSON Payload: " . mb_substr(json_encode($response), 0, 1000);
            $this->getLogger()->error($msg);
        }

        $filler = new taoQtiCommon_helpers_PciVariableFiller(
            $currentItem,
            $this->getServiceManager()->get(QtiFlysystemFileManager::SERVICE_ID)
        );

        if (is_array($response)) {
            foreach ($response as $id => $responseData) {
                try {
                    $var = $filler->fill($id, $responseData);
                    // Do not take into account QTI File placeholders.
                    if (\taoQtiCommon_helpers_Utils::isQtiFilePlaceHolder($var) === false) {
                        $responses->setVariable($var);
                    }
                } catch (\OutOfRangeException $e) {
                    $this->getLogger()->debug("Could not convert client-side value for variable '${id}'.");
                } catch (\OutOfBoundsException $e) {
                    $this->getLogger()->debug("Could not find variable with identifier '${id}' in current item.");
                }
            }
        } else {
            $this->getLogger()->error('Invalid json payload');
        }

        return $responses;
    }

    /**
     * Checks if the provided responses are empty
     * @param RunnerServiceContext $context
     * @param $responses
     * @return mixed
     * @throws common_Exception
     */
    public function emptyResponse(RunnerServiceContext $context, $responses)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'storeItemResponse');

        $similar = 0;

        /** @var ResponseVariable $responseVariable */
        foreach ($responses as $responseVariable) {
            $value = $responseVariable->getValue();
            $default = $responseVariable->getDefaultValue();

            // Similar to default ?
            if (TestRunnerUtils::isQtiValueNull($value) === true) {
                if (TestRunnerUtils::isQtiValueNull($default) === true) {
                    $similar++;
                }
            } elseif ($value->equals($default) === true) {
                $similar++;
            }
        }

        $respCount = count($responses);

        return $respCount > 0 && $similar === $respCount;
    }

    /**
     * Stores the response of a particular item
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @param $responses
     * @return boolean
     * @throws InvalidArgumentTypeException
     * @throws PhpStorageException
     * @throws AssessmentItemSessionException
     * @throws taoQtiCommon_helpers_ResultTransmissionException
     */
    public function storeItemResponse(RunnerServiceContext $context, $itemRef, $responses)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'storeItemResponse');

        $session = $this->getCurrentAssessmentSession($context);

        try {
            common_Logger::t('Responses sent from the client-side. The Response Processing will take place.');

            if ($context->isAdaptive()) {
                $session->beginItemSession();
                $session->beginAttempt();
                $session->endAttempt($responses);

                $assessmentItem = $session->getAssessmentItem();
                $assessmentItemIdentifier = $assessmentItem->getIdentifier();
                $score = $session->getVariable('SCORE');
                $output = $context->getLastCatItemOutput();

                if ($score !== null) {
                    $output[$assessmentItemIdentifier] = new ItemResult(
                        $assessmentItemIdentifier,
                        new ResultVariable(
                            $score->getIdentifier(),
                            BaseType::getNameByConstant($score->getBaseType()),
                            $score->getValue()->getValue(),
                            null,
                            $score->getCardinality()
                        ),
                        microtime(true)
                    );
                } else {
                    common_Logger::i(
                        "No 'SCORE' outcome variable for item '${assessmentItemIdentifier}' involved in an "
                        . "adaptive section."
                    );
                }

                $context->persistLastCatItemOutput($output);

                // Send results to TAO Results.
                $resultTransmitter = new taoQtiCommon_helpers_ResultTransmitter(
                    $context->getSessionManager()->getResultServer()
                );

                $hrefParts = explode('|', $assessmentItem->getHref());
                $sessionId = $context->getTestSession()->getSessionId();
                $itemIdentifier = $assessmentItem->getIdentifier();

                // Deal with attempts.
                $attempt = $context->getCatAttempts($itemIdentifier);
                $transmissionId = "${sessionId}.${itemIdentifier}.${attempt}";

                $attempt++;

                foreach ($session->getAllVariables() as $var) {
                    if ($var->getIdentifier() === 'numAttempts') {
                        $var->setValue(new QtiInteger($attempt));
                    }

                    $variables[] = $var;
                }

                $resultTransmitter->transmitItemVariable($variables, $transmissionId, $hrefParts[0], $hrefParts[2]);
                $context->persistCatAttempts($itemIdentifier, $attempt);

                $context->getTestSession()->endAttempt(new State(), true);
            } else {
                // Non adaptive case.
                $session->endAttempt($responses, true);
            }

            return true;
        } catch (AssessmentTestSessionException $e) {
            common_Logger::w($e->getMessage());
            return false;
        }
    }

    /**
     * Should we display feedbacks
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws InvalidArgumentTypeException
     */
    public function displayFeedbacks(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'displayFeedbacks');

        /* @var TestSession $session */
        $session = $context->getTestSession();

        if ($session->getCurrentSubmissionMode() === SubmissionMode::SIMULTANEOUS) {
            return false;
        }

        if ($context->getTestCompilationVersion() > 0) {
            return $session->getCurrentAssessmentItemSession()->getItemSessionControl()->mustShowFeedback();
        }

        return $this->getFeatureFlagChecker()->isEnabled('FEATURE_FLAG_FORCE_DISPLAY_TEST_ITEM_FEEDBACK')
            || $session->getCurrentAssessmentItemSession()->getItemSessionControl()->mustShowFeedback();
    }

    /**
     * Get feedback definitions
     *
     * @param RunnerServiceContext $context
     * @param string $itemRef  the item reference
     * @return array the feedbacks data
     * @throws common_Exception
     * @throws InvalidArgumentTypeException
     * @deprecated since version 30.7.0, to be removed in 31.0.0. Use getItemVariableElementsData() instead
     */
    public function getFeedbacks(RunnerServiceContext $context, $itemRef)
    {
        return $this->getItemVariableElementsData($context, $itemRef);
    }

    /**
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @return array
     * @throws common_Exception
     * @throws InvalidArgumentTypeException
     */
    public function getItemVariableElementsData(RunnerServiceContext $context, $itemRef)
    {
        $this->assertQtiRunnerServiceContext($context);

        return $this->loadItemData($itemRef, QtiJsonItemCompiler::VAR_ELT_FILE_NAME);
    }

    /**
     * Does the given item has feedbacks
     *
     * @param RunnerServiceContext $context
     * @param string $itemRef  the item reference
     * @return boolean
     * @throws common_Exception
     * @throws common_exception_InconsistentData
     * @throws InvalidArgumentTypeException
     * @throws tao_models_classes_FileNotFoundException
     */
    public function hasFeedbacks(RunnerServiceContext $context, $itemRef)
    {
        $hasFeedbacks     = false;
        $displayFeedbacks = $this->displayFeedbacks($context);
        if ($displayFeedbacks) {
            $feedbacks = $this->getFeedbacks($context, $itemRef);
            foreach ($feedbacks as $entry) {
                if (isset($entry['feedbackRules'])) {
                    if (count($entry['feedbackRules']) > 0) {
                        $hasFeedbacks = true;
                    }
                    break;
                }
            }
        }

        return $hasFeedbacks;
    }

    /**
     * Should we display feedbacks
     * @param RunnerServiceContext $context
     * @return array the item session
     * @throws InvalidArgumentTypeException
     */
    public function getItemSession(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'getItemSession');

        /* @var TestSession $session */
        $session = $context->getTestSession();

        $currentItem       = $session->getCurrentAssessmentItemRef();
        $currentOccurrence = $session->getCurrentAssessmentItemRefOccurence();

        $itemSession = $session->getAssessmentItemSessionStore()->getAssessmentItemSession(
            $currentItem,
            $currentOccurrence
        );

        $stateOutput = new taoQtiCommon_helpers_PciStateOutput();

        foreach ($itemSession->getAllVariables() as $var) {
            $stateOutput->addVariable($var);
        }

        $output = $stateOutput->getOutput();

        // The current item answered state
        $route    = $session->getRoute();
        $position = $route->getPosition();
        $config = $this->getTestConfig();
        $reviewConfig = $config->getConfigValue('review');
        $partiallyAnsweredIsAnswered = isset($reviewConfig['partiallyAnsweredIsAnswered'])
            ? (bool) $reviewConfig['partiallyAnsweredIsAnswered']
            : true;

        $output['itemAnswered'] = TestRunnerUtils::isItemCompleted(
            $route->getRouteItemAt($position),
            $itemSession,
            $partiallyAnsweredIsAnswered
        );

        return $output;
    }

    /**
     * Moves the current position to the provided scoped reference.
     * @param RunnerServiceContext $context
     * @param $direction
     * @param $scope
     * @param $ref
     * @return boolean
     * @throws common_Exception
     */
    public function move(RunnerServiceContext $context, $direction, $scope, $ref)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'move');

        $result = true;

        try {
            $result = QtiRunnerNavigation::move($direction, $scope, $context, $ref);
        } catch (AssessmentTestSessionException $e) {
        } finally {
            if ($result && (!isset($e) || in_array($e->getCode(), self::TIMEOUT_EXCEPTION_CODES, true))) {
                $this->continueInteraction($context);
            }
        }

        return $result;
    }

    /**
     * Skips the current position to the provided scoped reference
     * @param RunnerServiceContext $context
     * @param $scope
     * @param $ref
     * @return boolean
     * @throws common_Exception
     */
    public function skip(RunnerServiceContext $context, $scope, $ref)
    {
        return $this->move($context, 'skip', $scope, $ref);
    }

    /**
     * Handles a test timeout
     * @param RunnerServiceContext $context
     * @param $scope
     * @param $ref
     * @param $late
     * @return boolean
     * @throws common_Exception
     */
    public function timeout(RunnerServiceContext $context, $scope, $ref, $late = false)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'timeout');

        /* @var TestSession $session */
        $session = $context->getTestSession();
        if ($context->isAdaptive()) {
            $this->getLogger()->debug("Select next item before timeout");
            $context->selectAdaptiveNextItem();
        }
        try {
            $session->closeTimer($ref, $scope);
            if ($late) {
                if ($scope == 'assessmentTest') {
                    $code = AssessmentTestSessionException::ASSESSMENT_TEST_DURATION_OVERFLOW;
                } elseif ($scope == 'testPart') {
                    $code = AssessmentTestSessionException::TEST_PART_DURATION_OVERFLOW;
                } elseif ($scope == 'assessmentSection') {
                    $code = AssessmentTestSessionException::ASSESSMENT_SECTION_DURATION_OVERFLOW;
                } else {
                    $code = AssessmentTestSessionException::ASSESSMENT_ITEM_DURATION_OVERFLOW;
                }
                throw new AssessmentTestSessionException("Maximum duration of ${scope} '${ref}' not respected.", $code);
            } else {
                $session->checkTimeLimits(false, true, false);
            }
        } catch (AssessmentTestSessionException $e) {
            $this->onTimeout($context, $e);
        }

        return true;
    }

    /**
     * Exits the test before its end
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws common_Exception
     */
    public function exitTest(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'exitTest');

        /* @var TestSession $session */
        $session = $context->getTestSession();
        $sessionId = $session->getSessionId();
        $this->getLogger()->info(
            "The user has requested termination of the test session '{$sessionId}'"
        );

        if ($context->isAdaptive()) {
            $this->getLogger()->debug('Select next item before test exit');
            $context->selectAdaptiveNextItem();
        }

        $event = new TestExitEvent($session);
        $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger($event);

        $session->endTestSession();

        $this->finish($context, $this->getStateAfterExit());

        return true;
    }

    /**
     * Finishes the test
     * @param RunnerServiceContext $context
     * @param string $finalState
     * @return boolean
     * @throws common_Exception
     */
    public function finish(RunnerServiceContext $context, $finalState = DeliveryExecution::STATE_FINISHED)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'finish');

        $executionUri = $context->getTestExecutionUri();
        $userUri = common_session_SessionManager::getSession()->getUserUri();

        $executionService = TaoDeliveryServiceProxy::singleton();
        $deliveryExecution = $executionService->getDeliveryExecution($executionUri);

        if ($deliveryExecution->getUserIdentifier() == $userUri) {
            $this->getLogger()->info("Finishing the delivery execution {$executionUri}");
            $result = $deliveryExecution->setState($finalState);
        } else {
            $this->getLogger()->warning(
                "Non owner {$userUri} tried to finish deliveryExecution {$executionUri}"
            );
            $result = false;
        }

        $this->getServiceManager()->get(ExtendedStateService::SERVICE_ID)->clearEvents($executionUri);
        /** @var TestSession $session */
        $session = $context->getTestSession();
        $this->triggerDeliveryExecutionFinish($deliveryExecution, $session->isManualScored());

        return $result;
    }

    private function getResultsStorage(): RdsResultStorage
    {
        return $this->getServiceLocator()->get(ResultServerService::SERVICE_ID)->getResultStorage();
    }

    private function triggerDeliveryExecutionFinish(DeliveryExecution $deliveryExecution, bool $isManualScored): void
    {
        $outcomeVariables = $this->getResultsStorage()->getDeliveryVariables($deliveryExecution->getIdentifier());
        $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger(
            new DeliveryExecutionFinish(
                $deliveryExecution,
                $outcomeVariables,
                $isManualScored
            )
        );
    }

    /**
     * Sets the test to paused state
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws common_Exception
     */
    public function pause(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'pause');

        $context->getTestSession()->suspend();
        $this->persist($context);

        return true;
    }

    /**
     * Resumes the test from paused state
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws common_Exception
     */
    public function resume(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, 'resume');

        $context->getTestSession()->resume();
        $this->persist($context);

        return true;
    }

    /**
     * Checks if the test is still valid
     * @param RunnerServiceContext $context
     * @return boolean
     * @throws common_Exception
     * @throws QtiRunnerClosedException
     */
    public function check(RunnerServiceContext $context)
    {
        $state = $context->getTestSession()->getState();

        if ($state == AssessmentTestSessionState::CLOSED) {
            throw new QtiRunnerClosedException();
        }

        return true;
    }

    /**
     * Checks if an item has been completed
     * @param RunnerServiceContext $context
     * @param RouteItem $routeItem
     * @param AssessmentItemSession $itemSession
     * @param bool $partially (optional) Whether or not consider partially responded sessions as responded.
     * @return bool
     * @throws common_Exception
     */
    public function isItemCompleted(RunnerServiceContext $context, $routeItem, $itemSession, $partially = true)
    {
        if ($context instanceof QtiRunnerServiceContext && $context->isAdaptive()) {
            $itemIdentifier = $context->getCurrentAssessmentItemRef()->getIdentifier();
            $itemState = $this->getItemState($context, $itemIdentifier);
            if ($itemState !== null) {
                // as the item comes from a CAT section, it is simpler to load the responses from the state
                $itemResponse = [];
                foreach ($itemState as $key => $value) {
                    if (isset($value['response'])) {
                        $itemResponse[$key] = $value['response'];
                    }
                }
                $responses = $this->parsesItemResponse($context, $itemIdentifier, $itemResponse);

                // fork of AssessmentItemSession::isResponded()
                $excludedResponseVariables = ['numAttempts', 'duration'];
                foreach ($responses as $var) {
                    if (
                        $var instanceof ResponseVariable
                        && in_array($var->getIdentifier(), $excludedResponseVariables) === false
                    ) {
                        $value = $var->getValue();
                        $defaultValue = $var->getDefaultValue();

                        if (Utils::isNull($value) === true) {
                            if (Utils::isNull($defaultValue) === (($partially) ? false : true)) {
                                return (($partially) ? true : false);
                            }
                        } else {
                            if ($value->equals($defaultValue) === (($partially) ? false : true)) {
                                return (($partially) ? true : false);
                            }
                        }
                    }
                }
            }

            return (($partially) ? false : true);
        } else {
            return TestRunnerUtils::isItemCompleted($routeItem, $itemSession, $partially);
        }
    }

    /**
     * Checks if the test is in paused state
     * @param RunnerServiceContext $context
     * @return boolean
     */
    public function isPaused(RunnerServiceContext $context)
    {
        return $context->getTestSession()->getState() == AssessmentTestSessionState::SUSPENDED;
    }

    /**
     * Checks if the test is in terminated state
     * @param RunnerServiceContext $context
     * @return boolean
     */
    public function isTerminated(RunnerServiceContext $context)
    {
        return $context->getTestSession()->getState() == AssessmentTestSessionState::CLOSED;
    }

    /**
     * Get the base url to the item public directory
     * @param RunnerServiceContext $context
     * @param string $itemRef
     * @return string
     * @throws common_Exception
     * @throws common_exception_Error
     * @throws InvalidArgumentTypeException
     */
    public function getItemPublicUrl(RunnerServiceContext $context, string $itemRef): string
    {
        if (!$context instanceof QtiRunnerServiceContext) {
            throw new InvalidArgumentTypeException(
                'QtiRunnerService',
                'getItemPublicUrl',
                0,
                QtiRunnerServiceContext::class,
                $context
            );
        }

        $directoryIds = explode('|', $itemRef);

        $userDataLang = common_session_SessionManager::getSession()->getDataLanguage();

        $directory = \tao_models_classes_service_FileStorage::singleton()->getDirectoryById($directoryIds[1]);
        // do fallback in case userlanguage is not default language
        if ($userDataLang != DEFAULT_LANG && !$directory->has($userDataLang) && $directory->has(DEFAULT_LANG)) {
            $userDataLang = DEFAULT_LANG;
        }

        return $directory->getPublicAccessUrl() . $userDataLang . '/';
    }

    /**
     * Comment the test
     * @param RunnerServiceContext $context
     * @param string $comment
     * @return bool
     */
    public function comment(RunnerServiceContext $context, $comment)
    {
        // prepare transmission Id for result server.
        $testSession = $context->getTestSession();
        $item = $testSession->getCurrentAssessmentItemRef()->getIdentifier();
        $occurrence = $testSession->getCurrentAssessmentItemRefOccurence();
        $sessionId = $testSession->getSessionId();
        $transmissionId = "${sessionId}.${item}.${occurrence}";

        /** @var DeliveryServerService $deliveryServerService */
        $deliveryServerService = $this->getServiceManager()->get(DeliveryServerService::SERVICE_ID);
        $resultStore = $deliveryServerService->getResultStoreWrapper($sessionId);

        $transmitter = new taoQtiCommon_helpers_ResultTransmitter($resultStore);

        // build variable and send it.
        $itemUri = TestRunnerUtils::getCurrentItemUri($testSession);
        $testUri = $testSession->getTest()->getUri();
        $variable = new ResponseVariable('comment', Cardinality::SINGLE, BaseType::STRING, new QtismString($comment));
        $transmitter->transmitItemVariable($variable, $transmissionId, $itemUri, $testUri);

        return true;
    }

    /**
     * Continue the test interaction if possible
     * @param RunnerServiceContext $context
     * @return bool
     */
    protected function continueInteraction(RunnerServiceContext $context)
    {
        $continue = false;

        /* @var TestSession $session */
        $session = $context->getTestSession();

        if ($session->isRunning() === true && $session->isTimeout() === false) {
            $event = new QtiContinueInteractionEvent($context, $this);
            $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger($event);

            TestRunnerUtils::beginCandidateInteraction($session);
            $continue = true;
        } else {
            $this->finish($context);
        }

        return $continue;
    }

    /**
     * Stuff to be undertaken when the Assessment Item presented to the candidate
     * times out.
     *
     * @param RunnerServiceContext $context
     * @param AssessmentTestSessionException $timeOutException The AssessmentTestSessionException object thrown to
     *                                                         indicate the timeout.
     */
    protected function onTimeout(RunnerServiceContext $context, AssessmentTestSessionException $timeOutException)
    {
        /* @var TestSession $session */
        $session = $context->getTestSession();

        $event = new TestTimeoutEvent($session, $timeOutException->getCode(), true);
        $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger($event);

        $isLinear = $session->getCurrentNavigationMode() === NavigationMode::LINEAR;
        $logContext = [];
        if ($context instanceof QtiRunnerServiceContext) {
            $logContext['deliveryExecutionId'] = $context->getTestExecutionUri();
        }
        switch ($timeOutException->getCode()) {
            case AssessmentTestSessionException::ASSESSMENT_TEST_DURATION_OVERFLOW:
                $this->getLogger()->info('TIMEOUT: closing the assessment test session', $logContext);
                $session->moveThroughAndEndTestSession();
                break;

            case AssessmentTestSessionException::TEST_PART_DURATION_OVERFLOW:
                if ($isLinear) {
                    $this->getLogger()->info('TIMEOUT: moving to the next test part', $logContext);
                    $session->moveNextTestPart();
                } else {
                    $this->getLogger()->info('TIMEOUT: closing the assessment test part', $logContext);
                    $session->closeTestPart();
                }
                break;

            case AssessmentTestSessionException::ASSESSMENT_SECTION_DURATION_OVERFLOW:
                if ($isLinear) {
                    $this->getLogger()->info('TIMEOUT: moving to the next assessment section', $logContext);
                    $session->moveNextAssessmentSection();
                } else {
                    $this->getLogger()->info('TIMEOUT: closing the assessment section session', $logContext);
                    $session->closeAssessmentSection();
                }
                break;

            case AssessmentTestSessionException::ASSESSMENT_ITEM_DURATION_OVERFLOW:
                if ($isLinear) {
                    $this->getLogger()->info('TIMEOUT: moving to the next item', $logContext);
                    $session->moveNextAssessmentItem();
                } else {
                    $this->getLogger()->info('TIMEOUT: closing the assessment item session', $logContext);
                    $session->closeAssessmentItem();
                }
                break;
        }

        $event = new TestTimeoutEvent($session, $timeOutException->getCode(), false);
        $this->getServiceManager()->get(EventManager::SERVICE_ID)->trigger($event);

        $this->continueInteraction($context);
    }

    /**
     * Build an array where each cell represent a time constraint (a.k.a. time limits)
     * in force. Each cell is actually an array with two keys:
     *
     * * 'source': The identifier of the QTI component emitting the constraint
     *   (e.g. AssessmentTest, TestPart, AssessmentSection, AssessmentItemRef).
     * * 'seconds': The number of remaining seconds until it times out.
     *
     * @param RunnerServiceContext $context
     * @return array
     */
    protected function buildTimeConstraints(RunnerServiceContext $context)
    {
        $constraints = [];

        $session = $context->getTestSession();
        foreach ($session->getRegularTimeConstraints() as $constraint) {
            if ($constraint->getMaximumRemainingTime() != false || $constraint->getMinimumRemainingTime() != false) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Stores trace variable related to an item, a test or a section
     *
     * @param RunnerServiceContext $context
     * @param string|null $itemUri
     * @param string $variableIdentifier
     * @param mixed $variableValue
     * @return boolean
     * @throws common_Exception
     */
    public function storeTraceVariable(
        RunnerServiceContext $context,
        ?string $itemUri,
        string $variableIdentifier,
        $variableValue
    ): bool {
        $this->assertQtiRunnerServiceContext($context);

        $metaVariable = $this->getTraceVariable($variableIdentifier, $variableValue);

        return $this->storeVariable($context, $itemUri, $metaVariable);
    }

    /**
     * Create a trace variable from variable identifier and value
     *
     * @param $variableIdentifier
     * @param $variableValue
     * @return taoResultServer_models_classes_TraceVariable
     * @throws InvalidArgumentTypeException
     */
    public function getTraceVariable($variableIdentifier, $variableValue)
    {
        if (!is_string($variableValue) && !is_numeric($variableValue)) {
            $variableValue = json_encode($variableValue);
        }

        $metaVariable = new taoResultServer_models_classes_TraceVariable();
        $metaVariable->setIdentifier($variableIdentifier);
        $metaVariable->setBaseType('string');
        $metaVariable->setCardinality(Cardinality::getNameByConstant(Cardinality::SINGLE));
        $metaVariable->setTrace($variableValue);

        return $metaVariable;
    }

    /**
     * Stores outcome variable related to an item, a test or a section
     *
     * @param RunnerServiceContext $context
     * @param $itemUri
     * @param $variableIdentifier
     * @param $variableValue
     * @return boolean
     * @throws common_Exception
     */
    public function storeOutcomeVariable(RunnerServiceContext $context, $itemUri, $variableIdentifier, $variableValue)
    {
        $this->assertQtiRunnerServiceContext($context);
        $metaVariable = $this->getOutcomeVariable($variableIdentifier, $variableValue);
        return $this->storeVariable($context, $itemUri, $metaVariable);
    }

    /**
     * Create an outcome variable from variable identifier and value
     *
     * @param $variableIdentifier
     * @param $variableValue
     * @return \taoResultServer_models_classes_OutcomeVariable
     * @throws InvalidArgumentTypeException
     */
    public function getOutcomeVariable($variableIdentifier, $variableValue)
    {
        if (!is_string($variableValue) && !is_numeric($variableValue)) {
            $variableValue = json_encode($variableValue);
        }
        $metaVariable = new \taoResultServer_models_classes_OutcomeVariable();
        $metaVariable->setIdentifier($variableIdentifier);
        $metaVariable->setBaseType('string');
        $metaVariable->setCardinality(Cardinality::getNameByConstant(Cardinality::SINGLE));
        $metaVariable->setValue($variableValue);

        return $metaVariable;
    }

    /**
     * Stores response variable related to an item, a test or a section
     *
     * @param RunnerServiceContext $context
     * @param $itemUri
     * @param $variableIdentifier
     * @param $variableValue
     * @return boolean
     * @throws common_Exception
     */
    public function storeResponseVariable(RunnerServiceContext $context, $itemUri, $variableIdentifier, $variableValue)
    {
        $this->assertQtiRunnerServiceContext($context);
        $metaVariable = $this->getResponseVariable($variableIdentifier, $variableValue);
        return $this->storeVariable($context, $itemUri, $metaVariable);
    }

    /**
     * Create a response variable from variable identifier and value
     *
     * @param $variableIdentifier
     * @param $variableValue
     * @return \taoResultServer_models_classes_ResponseVariable
     * @throws InvalidArgumentTypeException
     */
    public function getResponseVariable($variableIdentifier, $variableValue)
    {
        if (!is_string($variableValue) && !is_numeric($variableValue)) {
            $variableValue = json_encode($variableValue);
        }
        $metaVariable = new \taoResultServer_models_classes_ResponseVariable();
        $metaVariable->setIdentifier($variableIdentifier);
        $metaVariable->setBaseType('string');
        $metaVariable->setCardinality(Cardinality::getNameByConstant(Cardinality::SINGLE));
        $metaVariable->setValue($variableValue);

        return $metaVariable;
    }

    /**
     * Store a set of result variables to the result server
     *
     * @param QtiRunnerServiceContext $context
     * @param string $itemUri This is the item uri
     * @param taoResultServer_models_classes_Variable[] $metaVariables
     * @param null $itemId The assessment item ref id (optional)
     * @return bool
     * @throws Exception
     * @throws common_exception_NotImplemented If the given $itemId is not the current assessment item ref
     */
    public function storeVariables(
        QtiRunnerServiceContext $context,
        $itemUri,
        $metaVariables,
        $itemId = null
    ) {
        $sessionId = $context->getTestSession()->getSessionId();

        /** @var DeliveryServerService $deliveryServerService */
        $deliveryServerService = $this->getServiceManager()->get(DeliveryServerService::SERVICE_ID);
        $resultStore = $deliveryServerService->getResultStoreWrapper($sessionId);

        $testUri = $context->getTestDefinitionUri();

        if (!is_null($itemUri)) {
            $resultStore->storeItemVariables(
                $testUri,
                $itemUri,
                $metaVariables,
                $this->getTransmissionId($context, $itemId)
            );
        } else {
            $resultStore->storeTestVariables($testUri, $metaVariables, $sessionId);
        }

        return true;
    }

    /**
     * Store a result variable to the result server
     *
     * @param QtiRunnerServiceContext $context
     * @param string $itemUri This is the item identifier
     * @param taoResultServer_models_classes_Variable $metaVariable
     * @param null $itemId The assessment item ref id (optional)
     * @return bool
     * @throws common_exception_NotImplemented If the given $itemId is not the current assessment item ref
     */
    protected function storeVariable(
        QtiRunnerServiceContext $context,
        $itemUri,
        taoResultServer_models_classes_Variable $metaVariable,
        $itemId = null
    ) {
        $sessionId = $context->getTestSession()->getSessionId();

        $testUri = $context->getTestDefinitionUri();

        /** @var DeliveryServerService $deliveryServerService */
        $deliveryServerService = $this->getServiceManager()->get(DeliveryServerService::SERVICE_ID);
        $resultStore = $deliveryServerService->getResultStoreWrapper($sessionId);

        if (!is_null($itemUri)) {
            $resultStore->storeItemVariable(
                $testUri,
                $itemUri,
                $metaVariable,
                $this->getTransmissionId($context, $itemId)
            );
        } else {
            $resultStore->storeTestVariable($testUri, $metaVariable, $sessionId);
        }

        return true;
    }

    /**
     * Build the transmission based on context and item ref id to store Item variables
     *
     * @param QtiRunnerServiceContext $context
     * @param null $itemId The item ref identifier
     * @return string The transmission id to store item variables
     * @throws common_exception_NotImplemented If the given $itemId is not the current assessment item ref
     */
    protected function getTransmissionId(QtiRunnerServiceContext $context, $itemId = null)
    {
        if (is_null($itemId)) {
            $itemId = $context->getCurrentAssessmentItemRef();
        } elseif ($itemId != $context->getCurrentAssessmentItemRef()) {
            throw new common_exception_NotImplemented('Item variables can be stored only for the current item');
        }

        $sessionId = $context->getTestSession()->getSessionId();
        $currentOccurrence = $context->getTestSession()->getCurrentAssessmentItemRefOccurence();

        return $sessionId . '.' . $itemId . '.' . $currentOccurrence;
    }

    /**
     * Check if the given RunnerServiceContext is a QtiRunnerServiceContext
     *
     * @param RunnerServiceContext $context
     * @throws InvalidArgumentTypeException
     */
    public function assertQtiRunnerServiceContext(RunnerServiceContext $context)
    {
        $this->assertIsQtiRunnerServiceContext($context, __FUNCTION__);
    }

    /**
     * Starts the timer for the current item in the TestSession
     * @param RunnerServiceContext $context
     * @param float|null $timestamp allow to start the timer at a specific time, or use current when it's null
     * @return bool
     * @throws InvalidArgumentTypeException
     */
    public function startTimer(RunnerServiceContext $context, ?float $timestamp = null): bool
    {
        if (!$context instanceof QtiRunnerServiceContext) {
            throw new InvalidArgumentTypeException(
                'QtiRunnerService',
                'startTimer',
                0,
                QtiRunnerServiceContext::class,
                $context
            );
        }

        /* @var TestSession $session */
        $session = $context->getTestSession();
        if ($session->getState() === AssessmentTestSessionState::INTERACTING) {
            $session->startItemTimer($timestamp);
        }

        return true;
    }

    /**
     * Ends the timer for the current item in the TestSession
     * @param RunnerServiceContext $context
     * @param float|null $duration The client side duration to adjust the timer
     * @param float|null $timestamp allow to end the timer at a specific time, or use current when it's null
     * @return bool
     * @throws InvalidArgumentTypeException
     */
    public function endTimer(RunnerServiceContext $context, ?float $duration = null, ?float $timestamp = null): bool
    {
        if (!$context instanceof QtiRunnerServiceContext) {
            throw new InvalidArgumentTypeException(
                'QtiRunnerService',
                'endTimer',
                0,
                QtiRunnerServiceContext::class,
                $context
            );
        }

        /* @var TestSession $session */
        $session = $context->getTestSession();
        $session->endItemTimer($duration, $timestamp);

        return true;
    }

    /**
     * Switch the received client store ids. Put the received id if different from the last stored.
     * This enables us to check wether the stores has been changed during a test session.
     * @param RunnerServiceContext $context
     * @param string $receivedStoreId The identifier of the client side store
     * @return string the identifier of the LAST saved client side store
     * @throws InvalidArgumentTypeException
     */
    public function switchClientStoreId(RunnerServiceContext $context, $receivedStoreId)
    {
        if (!$context instanceof QtiRunnerServiceContext) {
            throw new InvalidArgumentTypeException(
                'QtiRunnerService',
                'switchClientStoreId',
                0,
                QtiRunnerServiceContext::class,
                $context
            );
        }

        /* @var TestSession $session */
        $session = $context->getTestSession();
        $sessionId = $session->getSessionId();

        $stateService = $this->getServiceManager()->get(ExtendedStateService::SERVICE_ID);
        $lastStoreId = $stateService->getStoreId($sessionId);

        if ($lastStoreId == false || $lastStoreId != $receivedStoreId) {
            $stateService->setStoreId($sessionId, $receivedStoreId);
        }

        return $lastStoreId;
    }

    /**
     * Get Current Assessment Session.
     *
     * Depending on the context (adaptive or not), it will return an appropriate Assessment Object to deal with.
     *
     * In case of the context is not adaptive, an AssessmentTestSession corresponding to the current test $context
     * is returned.
     *
     * Otherwise, an AssessmentItemSession to deal with is returned.
     *
     * @param \oat\taoQtiTest\models\runner\RunnerServiceContext $context
     * @return \qtism\runtime\tests\AssessmentTestSession|\qtism\runtime\tests\AssessmentItemSession
     */
    public function getCurrentAssessmentSession(RunnerServiceContext $context)
    {
        if ($context->isAdaptive()) {
            return new AssessmentItemSession($context->getCurrentAssessmentItemRef(), new SessionManager());
        } else {
            return $context->getTestSession();
        }
    }

    /**
     * @param TestSession $session
     * @param string $qtiClassName
     * @return null|string
     */
    public function getTimeLimitsFromSession(TestSession $session, $qtiClassName)
    {
        $maxTimeSeconds = null;
        $item = null;
        switch ($qtiClassName) {
            case 'assessmentTest':
                $item = $session->getAssessmentTest();
                break;
            case 'testPart':
                $item = $session->getCurrentTestPart();
                break;
            case 'assessmentSection':
                $item = $session->getCurrentAssessmentSection();
                break;
            case 'assessmentItemRef':
                $item = $session->getCurrentAssessmentItemRef();
                break;
        }

        if ($item && $limits = $item->getTimeLimits()) {
            $maxTimeSeconds = $limits->hasMaxTime()
                ? $limits->getMaxTime()->getSeconds(true)
                : $maxTimeSeconds;
        }

        return $maxTimeSeconds;
    }

    /**
     * @inheritdoc
     */
    public function deleteDeliveryExecutionData(DeliveryExecutionDeleteRequest $request)
    {
        /** @var StorageManager $storage */
        $storage = $this->getServiceLocator()->get(StorageManager::SERVICE_ID);
        $userUri = $request->getDeliveryExecution()->getUserIdentifier();
        /** @var TestSessionService $testSessionService */
        $testSessionService = $this->getServiceLocator()->get(TestSessionService::SERVICE_ID);
        $session = $testSessionService->getTestSession($request->getDeliveryExecution(), false);
        if ($session === null) {
            $status = $this->deleteExecutionStates(
                $request->getDeliveryExecution()->getIdentifier(),
                $userUri,
                $storage
            );
        } else {
            $status = $this->deleteExecutionStatesBasedOnSession($request, $storage, $userUri, $session);
        }

        /** @var ToolsStateStorage $toolsStateStorage */
        $toolsStateStorage = $this->getServiceLocator()->get(ToolsStateStorage::SERVICE_ID);
        $toolsStateStorage->deleteStates($request->getDeliveryExecution()->getIdentifier());

        return $status;
    }

    /**
     * @param RunnerServiceContext $context
     * @param $itemRef
     * @return array|string
     * @throws common_Exception
     * @throws common_exception_InconsistentData
     */
    public function getItemPortableElements(RunnerServiceContext $context, $itemRef)
    {
        $portableElementService = new PortableElementService();
        $portableElementService->setServiceLocator($this->getServiceLocator());

        $portableElements = [];
        try {
            $portableElements = $this->loadItemData($itemRef, QtiJsonItemCompiler::PORTABLE_ELEMENT_FILE_NAME);
            foreach ($portableElements as $portableModel => &$elements) {
                foreach ($elements as $typeIdentifier => &$versions) {
                    foreach ($versions as &$portableData) {
                        try {
                            $portableElementService->setBaseUrlToPortableData($portableData);
                        } catch (PortableElementNotFoundException $e) {
                            $this->getLogger()->warning(
                                'the portable element version does not exist in delivery server'
                            );
                        } catch (PortableModelMissing $e) {
                            $this->getLogger()->warning(
                                'the portable element model does not exist in delivery server'
                            );
                        }
                    }
                }
            }
        } catch (tao_models_classes_FileNotFoundException $e) {
            $this->getLogger()->info(
                'old delivery that does not contain the compiled portable element data in the item ' . $itemRef
            );
        }

        return $portableElements;
    }

    /**
     * @param $itemRef
     * @return array|mixed|string
     * @throws common_Exception
     */
    public function getItemMetadataElements($itemRef)
    {
        $metadataElements = [];
        try {
            $metadataElements = $this->loadItemData($itemRef, QtiJsonItemCompiler::METADATA_FILE_NAME);
        } catch (tao_models_classes_FileNotFoundException $e) {
            $this->getLogger()->info(
                'Old delivery that does not contain the compiled portable element data in the item ' . $itemRef
                . '. Original message: ' . $e->getMessage()
            );
        } catch (Exception $e) {
            $this->getLogger()->warning(
                'An exception caught during fetching item metadata elements. Original message: ' . $e->getMessage()
            );
        }
        return $metadataElements;
    }

    /**
     * @param $deUri
     * @param $userUri
     * @param StorageManager $storage
     * @return mixed
     */
    protected function deleteExecutionStates($deUri, $userUri, StorageManager $storage)
    {
        $stateStorage = $storage->getStorage();
        $persistence  = common_persistence_KeyValuePersistence::getPersistence(
            $stateStorage->getOption(tao_models_classes_service_StateStorage::OPTION_PERSISTENCE)
        );

        $driver = $persistence->getDriver();
        if ($driver instanceof common_persistence_AdvKeyValuePersistence) {
            $keys = $driver->keys(tao_models_classes_service_StateStorage::KEY_NAMESPACE . '*' . $deUri . '*');
            foreach ($keys as $key) {
                $driver->del($key);
            }

            return $storage->persist($userUri);
        }

        return false;
    }

    /**
     * @param DeliveryExecutionDeleteRequest $request
     * @param StorageManager $storage
     * @param $userUri
     * @param AssessmentTestSession $session
     * @return bool
     * @throws \common_exception_NotFound
     */
    protected function deleteExecutionStatesBasedOnSession(
        DeliveryExecutionDeleteRequest $request,
        StorageManager $storage,
        $userUri,
        AssessmentTestSession $session
    ) {
        $itemsRefs = $this->getItemsRefs($request, $session);
        foreach ($itemsRefs as $itemRef) {
            $stateId = $this->buildStorageItemKey(
                $request->getDeliveryExecution()->getIdentifier(),
                $itemRef
            );
            if ($storage->has($userUri, $stateId)) {
                $storage->del($userUri, $stateId);
            }
        }

        return $storage->persist($userUri);
    }

    /**
     * @param DeliveryExecutionDeleteRequest $request
     * @param AssessmentTestSession $session
     * @return array
     */
    protected function getItemsRefs(DeliveryExecutionDeleteRequest $request, AssessmentTestSession $session)
    {
        try {
            $itemsRefs = (new GetDeliveryExecutionsItems(
                $this->getServiceLocator()->get(RuntimeService::SERVICE_ID),
                $this->getServiceLocator()->get(CatService::SERVICE_ID),
                \tao_models_classes_service_FileStorage::singleton(),
                $request->getDeliveryExecution(),
                $session
            ))->getItemsRefs();
        } catch (Exception $exception) {
            $itemsRefs = [];
        }

        return $itemsRefs;
    }

    /**
     * Get state of delivery execution after exit triggered by test taker
     * @return string
     */
    protected function getStateAfterExit()
    {
        return DeliveryExecution::STATE_FINISHED;
    }

    /**
     * Returns that the Theme Switcher Plugin is enabled or not
     *
     * @return bool
     * @throws common_ext_ExtensionException
     */
    private function isThemeSwitcherEnabled()
    {
        /** @var common_ext_ExtensionsManager $extensionsManager */
        $extensionsManager = $this->getServiceLocator()->get(common_ext_ExtensionsManager::SERVICE_ID);
        $config = $extensionsManager->getExtensionById("taoTests")->getConfig("test_runner_plugin_registry");

        return array_key_exists(self::TOOL_ITEM_THEME_SWITCHER_KEY, $config)
            && $config[self::TOOL_ITEM_THEME_SWITCHER_KEY]["active"] === true;
    }

    /**
     * Returns the ID of the current theme
     *
     * @return string
     * @throws common_exception_InconsistentData
     */
    private function getCurrentThemeId()
    {
        /** @var ThemeService $themeService */
        $themeService = $this->getServiceLocator()->get(ThemeService::SERVICE_ID);

        return $themeService->getTheme()->getId();
    }

    private function getUpdateItemContentReferencesService(): UpdateItemContentReferencesService
    {
        return $this->getServiceLocator()->getContainer()->get(UpdateItemContentReferencesService::class);
    }

    private function getFeatureFlagChecker(): FeatureFlagCheckerInterface
    {
        return $this->getServiceLocator()->getContainer()->get(FeatureFlagChecker::class);
    }

    /**
     * @throws InvalidArgumentTypeException
     */
    private function assertIsQtiRunnerServiceContext(
        RunnerServiceContext $context,
        string $action
    ): void {
        if (!$context instanceof QtiRunnerServiceContext) {
            throw new InvalidArgumentTypeException(
                'QtiRunnerService',
                $action,
                0,
                QtiRunnerServiceContext::class,
                $context
            );
        }
    }
}
