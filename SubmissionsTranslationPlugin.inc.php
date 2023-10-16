<?php

/**
 * @file plugins/generic/submissionsTranslation/index.php
 *
 * Copyright (c) 2023 Lepidus Tecnologia
 * Distributed under the GNU GPL v3. For full terms see LICENSE or https://www.gnu.org/licenses/gpl-3.0.txt.
 *
 * @class SubmissionsTranslationPlugin
 * @ingroup plugins_generic_submissionsTranslation
 * @brief Main class of Submissions Translation plugin.
 *
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.submissionsTranslation.classes.TranslationsService');

class SubmissionsTranslationPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return true;
        }

        if ($success and $this->getEnabled($mainContextId)) {
            HookRegistry::register('Template::Workflow', array($this, 'addWorkflowModifications'));
            HookRegistry::register('TemplateManager::display', array($this, 'loadResourcesToWorkflow'));
            HookRegistry::register('Templates::Article::Main', array($this, 'addArticlePageModifications'));
            HookRegistry::register('Dispatcher::dispatch', array($this, 'setupSubmissionsTranslationHandler'));
            HookRegistry::register('Schema::get::submission', array($this, 'addOurFieldsToSubmissionSchema'));
        }

        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.submissionsTranslation.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.submissionsTranslation.description');
    }

    public function addOurFieldsToSubmissionSchema($hookName, $params)
    {
        $schema = & $params[0];

        $schema->properties->{'isTranslationOf'} = (object) [
            'type' => 'integer'
        ];

        return false;
    }

    public function addWorkflowModifications($hookName, $params)
    {
        $templateMgr = & $params[1];
        $submission = $templateMgr->get_template_vars('submission');
        $submissionIsTranslation = !is_null($submission->getData('isTranslationOf'));

        if($templateMgr->getTemplateVars('requestedPage') != 'workflow') {
            return false;
        }

        if($submissionIsTranslation) {
            $templateMgr->registerFilter("output", array($this, 'refTranslatedWorkflowFilter'));
        } else {
            $templateMgr->registerFilter("output", array($this, 'nonTranslationWorkflowFilter'));

            $translationsService = new TranslationsService();
            $translationsForDisplay = $translationsService->getTranslationsWorkflow($submission->getId());
            $templateMgr->assign([
                'hasTranslations' => (count($translationsForDisplay) > 0),
                'translations' => $translationsForDisplay
            ]);
        }

        return false;
    }

    public function nonTranslationWorkflowFilter($output, $templateMgr)
    {
        return $this->workflowActionsFilter($output, $templateMgr, 'nonTranslationWorkflow');
    }

    public function refTranslatedWorkflowFilter($output, $templateMgr)
    {
        return $this->workflowActionsFilter($output, $templateMgr, 'refTranslatedWorkflow');
    }

    private function workflowActionsFilter($output, $templateMgr, $templateName)
    {
        $pattern = '/<template slot="actions">/';
        if (preg_match($pattern, $output, $matches, PREG_OFFSET_CAPTURE)) {
            $posBeginning = $matches[0][1];
            $patternLength = strlen($pattern) - 2;

            $nonTranslationTemplate = $templateMgr->fetch($this->getTemplateResource($templateName . '.tpl'));

            $output = substr_replace($output, $nonTranslationTemplate, $posBeginning + $patternLength, 0);
            $templateMgr->unregisterFilter('output', array($this, $templateName . 'Filter'));
        }
        return $output;
    }

    public function loadResourcesToWorkflow($hookName, $params)
    {
        $templateMgr = $params[0];
        $template = $params[1];
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        if ($template == 'workflow/workflow.tpl') {
            $submission = $templateMgr->get_template_vars('submission');

            if(is_null($submission->getData('isTranslationOf'))) {
                $this->addCreateTranslationForm($templateMgr, $request);
            } else {
                $translatedSubmissionId = $submission->getData('isTranslationOf');
                $translatedSubmissionUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $translatedSubmissionId);
                $templateMgr->setState([
                    'translatedSubmissionUrl' => $translatedSubmissionUrl
                ]);
            }
        }

        return false;
    }

    private function addCreateTranslationForm($templateMgr, $request)
    {
        $context = $request->getContext();
        $submission = $templateMgr->get_template_vars('submission');

        $this->import('classes.components.forms.CreateTranslationForm');
        $createTranslationUrl = $request->getDispatcher()->url($request, ROUTE_API, $context->getPath(), 'submissionsTranslation/create', null, null, ['submissionId' => $submission->getId()]);
        $createTranslationForm = new CreateTranslationForm($createTranslationUrl, $submission);

        $workflowComponents = $templateMgr->getState('components');
        $workflowComponents[$createTranslationForm->id] = $createTranslationForm->getConfig();

        $templateMgr->setState([
            'components' => $workflowComponents
        ]);
    }

    public function addArticlePageModifications($hookName, $params)
    {
        $templateMgr = & $params[1];
        $output = & $params[2];
        $submission = $templateMgr->get_template_vars('article');
        $submissionIsNotTranslation = is_null($submission->getData('isTranslationOf'));

        if($submissionIsNotTranslation) {
            $translationsService = new TranslationsService();
            $translations = $translationsService->getTranslationsArticlePage($submission->getId());

            if(count($translations) > 0) {
                $templateMgr->assign('translations', $translations);
                $output .= $templateMgr->fetch($this->getTemplateResource('listTranslationsArticlePage.tpl'));
            }
        }

        return false;
    }

    public function setupSubmissionsTranslationHandler($hookName, $request)
    {
        $router = $request->getRouter();
        if (!($router instanceof \APIRouter)) {
            return;
        }

        if (str_contains($request->getRequestPath(), 'api/v1/submissionsTranslation')) {
            $this->import('api.v1.submissionsTranslation.SubmissionsTranslationHandler');
            $handler = new SubmissionsTranslationHandler();
        }

        if (!isset($handler)) {
            return;
        }

        $router->setHandler($handler);
        $handler->getApp()->run();
        exit;
    }
}
