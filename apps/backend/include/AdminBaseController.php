<?php
abstract class AdminBaseController extends \Flywheel\Controller\WebController {
    public function beforeExecute() {
        parent::beforeExecute();
        $this->loadPlugin();
        if($this->getName() != 'Auth' && !BackendAuth::getInstance()->isAuthenticated()) {
            $this->redirect(
                $this->createUrl('auth/login', array(
                    'r' => urlencode(\Flywheel\Factory::getRouter()->getUrl()))));
        }
    }

    public function loadPlugin() {
        /** @var Extension[] $extensions */
        $extensions = \Extension::findByStatus(1);
        foreach($extensions as $extension) {
            $basePath = ROOT_PATH .'/extension/' .(($extension->type == 'PLUGIN')? 'plugin/' : 'module/');
            require_once $basePath .$extension->path;
        }
    }

    protected function _beforeRender() {
        $this->view()->assign('controller', $this);
        parent::_beforeRender();
    }

    /**
     * get current login user
     * return Users
     */
    public function getSessionUser() {
        return BackendAuth::getInstance()->getUser();
    }
}
