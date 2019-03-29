<?php
/**
 * Nkr Record Controller
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2019.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace Finna\Controller;

/**
 * Nkr Record Controller
 *
 * @category VuFind
 * @package  Controller
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class NkrrecordController extends RecordController
{
    use \Finna\Controller\NkrControllerTrait;

    /**
     * Type of record to display
     *
     * @var string
     */
    protected $searchClassId = 'Nkr';

    /**
     * Home (default) action -- forward to requested (or default) tab.
     *
     * @return mixed
     */
    public function homeAction()
    {
        $view = parent::homeAction();
        $view = $this->handleAutoOpenRegistration($view);

        return $view;
    }
    
    /**
     * Create a new ViewModel.
     *
     * @param array $params Parameters to pass to ViewModel constructor.
     *
     * @return \Zend\View\Model\ViewModel
     */
    protected function createViewModel($params = null)
    {
        $view = parent::createViewModel($params);
        $this->layout()->searchClassId = $view->searchClassId = $this->searchClassId;
        $view->driver = $this->loadRecord();
        $view->unrestrictedDriver = $this->loadRecordWithRestrictedData();
        
        return $view;
    }

    /**
     * Load record with restricted metadata.
     *
     * @return \VuFind\RecordDriver\AbstractBase
     */
    public function loadRecordWithRestrictedData()
    {
        $params = [];
        try {
            $response = $this->permission()->check('finna.authorized', false);
            // TODO verify
            if ($response === null) {
                if ($user = $this->getUser()) {
                    $params['user'] = $user->username;
                }
            }
        } catch (\Exception $e) {
        }
        
        $recordLoader
            = $this->serviceLocator->build('VuFind\Record\Loader', $params);

        return $recordLoader->load(
            $this->params()->fromRoute('id', $this->params()->fromQuery('id')),
            $this->searchClassId,
            false
        );
    }
}
