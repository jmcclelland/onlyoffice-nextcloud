<?php
/**
 *
 * (c) Copyright Ascensio System SIA 2021
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace OCA\Onlyoffice\AppInfo;

use OC\EventDispatcher\SymfonyAdapter;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\DirectEditing\RegisterDirectEditorEvent;
use OCP\Util;
use OCP\IPreview;

use OCA\Viewer\Event\LoadViewer;

use OCA\Onlyoffice\AppConfig;
use OCA\Onlyoffice\Controller\CallbackController;
use OCA\Onlyoffice\Controller\EditorController;
use OCA\Onlyoffice\Controller\SettingsController;
use OCA\Onlyoffice\Controller\TemplateController;
use OCA\Onlyoffice\Crypt;
use OCA\Onlyoffice\DirectEditor;
use OCA\Onlyoffice\Hooks;
use OCA\Onlyoffice\Preview;

use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {

    /**
     * Application configuration
     *
     * @var AppConfig
     */
    public $appConfig;

    /**
     * Hash generator
     *
     * @var Crypt
     */
    public $crypt;

    public function __construct(array $urlParams = []) {
        $appName = "onlyoffice";

        parent::__construct($appName, $urlParams);

        $this->appConfig = new AppConfig($appName);
        $this->crypt = new Crypt($this->appConfig);
    }

    public function register(IRegistrationContext $context): void {
        require_once __DIR__ . "/../3rdparty/jwt/BeforeValidException.php";
        require_once __DIR__ . "/../3rdparty/jwt/ExpiredException.php";
        require_once __DIR__ . "/../3rdparty/jwt/SignatureInvalidException.php";
        require_once __DIR__ . "/../3rdparty/jwt/JWT.php";

        $context->registerService("L10N", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getL10N($c->get("AppName"));
        });

        $context->registerService("RootStorage", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getRootFolder();
        });

        $context->registerService("UserSession", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getUserSession();
        });

        $context->registerService("UserManager", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getUserManager();
        });

        $context->registerService("Logger", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getLogger();
        });

        $context->registerService("URLGenerator", function (ContainerInterface $c) {
            return $c->get("ServerContainer")->getURLGenerator();
        });

        $context->registerService("DirectEditor", function (ContainerInterface $c) {
            return new DirectEditor(
                $c->get("AppName"),
                $c->get("URLGenerator"),
                $c->get("L10N"),
                $c->get("Logger"),
                $this->appConfig,
                $this->crypt
            );
        });

        // Controllers
        $context->registerService("SettingsController", function (ContainerInterface $c) {
            return new SettingsController(
                $c->get("AppName"),
                $c->get("Request"),
                $c->get("URLGenerator"),
                $c->get("L10N"),
                $c->get("Logger"),
                $this->appConfig,
                $this->crypt
            );
        });

        $context->registerService("EditorController", function (ContainerInterface $c) {
            return new EditorController(
                $c->get("AppName"),
                $c->get("Request"),
                $c->get("RootStorage"),
                $c->get("UserSession"),
                $c->get("UserManager"),
                $c->get("URLGenerator"),
                $c->get("L10N"),
                $c->get("Logger"),
                $this->appConfig,
                $this->crypt,
                $c->get("IManager"),
                $c->get("Session")
            );
        });

        $context->registerService("CallbackController", function (ContainerInterface $c) {
            return new CallbackController(
                $c->get("AppName"),
                $c->get("Request"),
                $c->get("RootStorage"),
                $c->get("UserSession"),
                $c->get("UserManager"),
                $c->get("L10N"),
                $c->get("Logger"),
                $this->appConfig,
                $this->crypt,
                $c->get("IManager")
            );
        });

        $context->registerService("TemplateController", function (ContainerInterface $c) {
            return new TemplateController(
                $c->get("AppName"),
                $c->get("Request"),
                $c->get("L10N"),
                $c->get("Logger")
            );
        });
    }

    public function boot(IBootContext $context): void {

        $context->injectFn(function (SymfonyAdapter $eventDispatcher) {

            $eventDispatcher->addListener('OCA\Files::loadAdditionalScripts',
                function() {
                    if (!empty($this->appConfig->GetDocumentServerUrl())
                        && $this->appConfig->SettingsAreSuccessful()
                        && $this->appConfig->isUserAllowedToUse()) {

                        Util::addScript("onlyoffice", "desktop");
                        Util::addScript("onlyoffice", "main");
                        Util::addScript("onlyoffice", "template");

                        if ($this->appConfig->GetSameTab()) {
                            Util::addScript("onlyoffice", "listener");
                        }

                        Util::addStyle("onlyoffice", "main");
                        Util::addStyle("onlyoffice", "template");
                    }
                });

            $eventDispatcher->addListener(LoadViewer::class,
                function () {
                    if (!empty($this->appConfig->GetDocumentServerUrl())
                        && $this->appConfig->SettingsAreSuccessful()
                        && $this->appConfig->isUserAllowedToUse()) {
                        Util::addScript("onlyoffice", "viewer");
                        Util::addScript("onlyoffice", "listener");

                        Util::addStyle("onlyoffice", "viewer");

                        $csp = new ContentSecurityPolicy();
                        $csp->addAllowedFrameDomain("'self'");
                        $cspManager = $this->getContainer()->getServer()->getContentSecurityPolicyManager();
                        $cspManager->addDefaultPolicy($csp);
                    }
                });

            $eventDispatcher->addListener('OCA\Files_Sharing::loadAdditionalScripts',
                function() {
                    if (!empty($this->appConfig->GetDocumentServerUrl())
                        && $this->appConfig->SettingsAreSuccessful()) {
                        Util::addScript("onlyoffice", "main");

                        if ($this->appConfig->GetSameTab()) {
                            Util::addScript("onlyoffice", "listener");
                        }

                        Util::addStyle("onlyoffice", "main");
                    }
                });

            $container = $this->getContainer();

            $previewManager = $container->query(IPreview::class);
            $previewManager->registerProvider(Preview::getMimeTypeRegex(), function() use ($container) {
                return $container->query(Preview::class);
            });

            $eventDispatcher->addListener(RegisterDirectEditorEvent::class,
                function (RegisterDirectEditorEvent $event) use ($container) {
                    if (!empty($this->appConfig->GetDocumentServerUrl())
                        && $this->appConfig->SettingsAreSuccessful()) {
                        $editor = $container->query(DirectEditor::class);
                        $event->register($editor);
                    }
                });
        });

        Hooks::connectHooks();
    }
}