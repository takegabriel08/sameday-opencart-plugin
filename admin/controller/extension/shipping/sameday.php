<?php

use Sameday\Exceptions\SamedayBadRequestException;
use Sameday\Exceptions\SamedaySDKException;
use Sameday\Objects\PostAwb\Request\ThirdPartyPickupEntityObject;
use Sameday\Objects\Types\AwbPdfType;
use Sameday\Requests\SamedayGetAwbPdfRequest;
use Sameday\Requests\SamedayGetLockersRequest;
use Sameday\Requests\SamedayGetPickupPointsRequest;
use Sameday\Requests\SamedayGetServicesRequest;
use Sameday\Requests\SamedayPostAwbRequest;
use Sameday\Sameday;

if (
    defined('STDIN')
    || php_sapi_name() === 'cli'
    || array_key_exists('SHELL', $_ENV)
    || (empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0)
    || !array_key_exists('REQUEST_METHOD', $_SERVER)
) {
    // Running in cli.
    require_once __DIR__ . '/../../../config.php';
    require_once DIR_SYSTEM . 'startup.php';
    require_once DIR_SYSTEM . 'library/sameday-php-sdk/src/Sameday/autoload.php';

    /**
     * @throws \Exception
     */
    function samedayCliSync() {
        $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
        $testing = $db->query('SELECT value FROM '. DB_PREFIX. 'setting WHERE (code=\'sameday\' AND `key`=\'sameday_testing\') OR (code=\'shipping_sameday\' AND `key`=\'shipping_sameday_testing\') LIMIT 1')->row['value'];
        $username = $db->query('SELECT value FROM '. DB_PREFIX. 'setting WHERE (code=\'sameday\' AND `key`=\'sameday_username\') OR (code=\'shipping_sameday\' AND `key`=\'shipping_sameday_username\') LIMIT 1')->row['value'];
        $password = $db->query('SELECT value FROM '. DB_PREFIX. 'setting WHERE (code=\'sameday\' AND `key`=\'sameday_password\') OR (code=\'shipping_sameday\' AND `key`=\'shipping_sameday_password\') LIMIT 1')->row['value'];
        $lastTs = $db->query('SELECT * FROM '. DB_PREFIX. 'setting WHERE (code=\'sameday\' AND `key`=\'sameday_sync_until_ts\') OR (code=\'shipping_sameday\' AND `key`=\'shipping_sameday_sync_until_ts\') LIMIT 1')->row;
        $time = time();

        if ($time <= $lastTs['value']) {
            // Already synced until now.
            return;
        }

        // How much should sync (maximum 2 hours).
        $period = min($time - $lastTs['value'], 6200);

        $sameday = new Sameday(new \Sameday\SamedayClient(
            $username,
            $password,
            $testing ? 'https://sameday-api.demo.zitec.com' : 'https://api.sameday.ro',
            'opencart',
            'sync'
        ));

        $statuses = [];
        $page = 1;
        while (true) {
            $request = new \Sameday\Requests\SamedayGetStatusSyncRequest($lastTs['value'], $lastTs['value'] + $period);
            $request->setPage($page++);
            try {
                $sync = $sameday->getStatusSync($request);
                if (!$sync->getStatuses()) {
                    // No more statuses.
                    break;
                }
            } catch (\Exception $e) {
                return;
            }

            // Build all statuses.
            foreach ($sync->getStatuses() as $status) {
                $statuses[$status->getParcelAwbNumber()][] = $status;
            }
        }

        foreach ($statuses as $awb => $parcelStatuses) {
            // Find local parcel awb.
            $parcel = $db->query('SELECT * FROM '. DB_PREFIX ."sameday_package WHERE awb_parcel='{$db->escape($awb)}'")->row;
            if (!$parcel) {
                continue;
            }

            // Unserialize and fix "sync" field.
            $sync = unserialize($parcel['sync']);
            if ($sync === false) {
                $sync = array();
            }

            $db->query('UPDATE '. DB_PREFIX ."sameday_package SET sync='{$db->escape(serialize(array_merge($sync, $parcelStatuses)))}' WHERE order_id='{$db->escape($parcel['order_id'])}' AND awb_parcel='{$db->escape($parcel['awb_parcel'])}'");
        }

        $db->query('UPDATE '. DB_PREFIX ."setting SET value='{$db->escape($lastTs['value'] + $period)}' WHERE setting_id='{$db->escape($lastTs['setting_id'])}'");
    }

    try {
        samedayCliSync();
    } catch (Exception $e) { }
    exit;
}

require_once DIR_SYSTEM . 'library/sameday-php-sdk/src/Sameday/autoload.php';

class ControllerExtensionShippingSameday extends Controller
{
    private $error = array();

    const SAMEDAY_CONFIGS = [
        'username',
        'password',
        'testing',
        'tax_class_id',
        'geo_zone_id',
        'status',
        'estimated_cost',
        'show_lockers_map',
        'locker_max_items',
        'sort_order',
        'host_country',
    ];

    const TOGGLE_HTML_ELEMENT = [
        'show' => 'block',
        'hide' => 'none',
    ];

    const IMPORT_LOCAL_DATA_ACTIONS = [
        'importServices',
        'importPickupPoint',
        'importLockers',
    ];

    /**
     * @var null
     */
    private $testing;

    /**
     * @var null
     */
    private $hostCountry;

    /**
     * @var SamedayHelper
     */
    private $samedayHelper;

    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->load->model('extension/shipping/sameday');

        $this->samedayHelper = Samedayclasses::getSamedayHelper(
            $this->buildRequest(self::SAMEDAY_CONFIGS),
            $registry,
            $this->model_extension_shipping_sameday->getPrefix()
        );
    }

    public function install()
    {
        $this->model_extension_shipping_sameday->install();

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(
            $this->model_extension_shipping_sameday->getPrefix() . "sameday",
            [$this->model_extension_shipping_sameday->getKey('sameday_sync_until_ts') => time()]
        );

        $this->model_setting_setting->editSetting(
            $this->model_extension_shipping_sameday->getPrefix() . "sameday",
            [$this->model_extension_shipping_sameday->getKey('sameday_sync_lockers_ts') => 0]
        );
    }

    public function uninstall()
    {
        $this->model_extension_shipping_sameday->uninstall();
    }

    /**
     * @throws SamedaySDKException
     */
    public function index()
    {
        $this->load->language('extension/shipping/sameday');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_sync_until_ts')] = $this->getConfig('sameday_sync_until_ts');
            $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_sync_lockers_ts')] = $this->getConfig('sameday_sync_lockers_ts');
            $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_testing')] = $this->getConfig('sameday_testing');
            $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_host_country')] = $this->getConfig('sameday_host_country') ?? $this->samedayHelper::API_HOST_LOCALE_RO;

            if (null !== $this->testing && null !== $this->hostCountry) {
                $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_testing')] = $this->testing;
                $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_host_country')] = $this->hostCountry;
            }

            // Add custom sanitization for password
            $passKey = $this->model_extension_shipping_sameday->getKey('sameday_password');
            $password = $this->model_extension_shipping_sameday->sanitizeInput($_POST[$passKey]);
            if ('' === $password) {
                $password = $this->getConfig('sameday_password');
                if ('' === $password || null === $password) {
                    $this->session->data['error_warning'] = $this->language->get('error_username_password');

                    return $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
                }
            }
            $this->request->post[$passKey] = $password;

            $this->model_setting_setting->editSetting(
                $this->model_extension_shipping_sameday->getPrefix() . "sameday",
                $this->request->post
            );

            $this->session->data['error_success'] = $this->language->get('text_success');

            return $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
        }

        $this->load->model('localisation/tax_class');
        $this->load->model('localisation/geo_zone');

        $data = $this->buildLanguage(array(
            'heading_title',
            'button_save',
            'button_cancel',

            'text_edit',
            'text_services',
            'text_services_refresh',
            'text_lockers',
            'text_lockers_refresh',
            'text_services_empty',
            'text_lockers_empty',
            'text_enabled',
            'text_disabled',
            'text_pickup_points',
            'text_pickup_points_refresh',
            'text_pickup_points_empty',
            'text_services_status_always',
            'text_none',
            'text_all_zones',

            'entry_username',
            'entry_password',
            'entry_testing',
            'entry_tax_class',
            'entry_geo_zone',
            'entry_status',
            'entry_estimated_cost',
            'entry_show_lockers_map',
            'entry_locker_max_items',
            'entry_sort_order',
            'entry_import_local_data',

            'column_internal_id',
            'column_internal_name',
            'column_name',
            'column_price',
            'column_price_free',
            'column_status',

            'column_locker_name',
            'column_locker_county',
            'column_locker_city',
            'column_locker_address',
            'column_locker_lat',
            'column_locker_lng',
            'column_locker_postal_code',

            'column_pickupPoint_samedayId',
            'column_pickupPoint_alias',
            'column_pickupPoint_city',
            'column_pickupPoint_county',
            'column_pickupPoint_address',
            'column_pickupPoint_default_address',
            'yes',
            'no',
        ));

        $data['error_warning'] = $this->buildError('warning');
        $data['error_success'] = $this->buildError('success');

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->addToken(), true)
            ),
            array(
                'text' => $this->language->get('text_shipping'),
                'href' => $this->url->link($this->getRouteExtension(), $this->addToken(array('type' => 'shipping')), true)
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/shipping/sameday', $this->addToken(), true)
            )
        );

        $data['statuses'] = $this->getStatuses();
        $data['action'] = $this->url->link('extension/shipping/sameday', $this->addToken(), true);
        $data['cancel'] = $this->url->link($this->getRouteExtension(), $this->addToken(array('type' => 'shipping')), true);
        $data['services'] = $this->model_extension_shipping_sameday->getServices($this->getConfig('sameday_testing'));
        $data['import_local_data_actions'] = json_encode(self::IMPORT_LOCAL_DATA_ACTIONS, true);
        $data['import_local_data_href'] = $this->url->link('extension/shipping/sameday/importLocalData', $this->addToken(), true);
        $data['service_refresh'] = $this->url->link('extension/shipping/sameday/serviceRefresh', $this->addToken(), true);
        $data['pickupPoints'] = $this->model_extension_shipping_sameday->getPickupPoints($this->getConfig('sameday_testing'));
        $data['lockers'] = $this->model_extension_shipping_sameday->getLockers($this->getConfig('sameday_testing'));
        $data['pickupPoint_refresh'] = $this->url->link('extension/shipping/sameday/pickupPointRefresh', $this->addToken(), true);
        $data['lockers_refresh'] = $this->url->link('extension/shipping/sameday/lockersRefresh', $this->addToken(), true);
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $data['service_links'] = array_map(
            function ($service) {
                return $this->url->link('extension/shipping/sameday/service', $this->addToken(array('id' => $service['id'])), true);
            },
            $data['services']
        );

        $data = array_merge($data, $this->buildRequest(self::SAMEDAY_CONFIGS));

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/shipping/sameday', $data));
    }

    /**
     * @return mixed
     */
    private function isTesting()
    {
        return $this->getConfig('sameday_testing');
    }

    public function importLocalData()
    {
        $action = $this->request->post['action'] ?? null;
        if (! in_array($action, self::IMPORT_LOCAL_DATA_ACTIONS, true)) {
            return $this->response->setOutput(json_encode(['error' => 'Invalid action!']));
        }

        try {
            $this->{$action}();
        } catch (Exception $exception) {
            return $this->response->setOutput(json_encode(['error' => $exception->getMessage()]));
        }

        return $this->response->setOutput(json_encode($action));
    }

    private function importLockers($redirectToPage = false)
    {
        $this->model_extension_shipping_sameday->install();

        $sameday = new Sameday($this->samedayHelper->initClient());

        $request = new SamedayGetLockersRequest();

        $remoteLockers = [];
        $page = 1;

        do {
            $request->setPage($page++);
            try {
                $lockers = $sameday->getLockers($request);
            } catch (\Exception $e) {
                $errorMessage = sprintf('Import Lockers error: %s', $e->getMessage());

                $this->session->data['error_warning'] = sprintf('%s &#8226; %s',
                    $this->session->data['error_warning'] ?? '',
                    $errorMessage
                );

                if ($redirectToPage) {
                    $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
                } else {
                    throw new \RuntimeException($errorMessage);
                }
            }

            foreach ($lockers->getLockers() as $lockerObject) {
                $locker = $this->model_extension_shipping_sameday->getLockerSameday($lockerObject->getId(), $this->isTesting());
                if (!$locker) {
                    $this->model_extension_shipping_sameday->addLocker($lockerObject, $this->isTesting());
                } else {
                    $this->model_extension_shipping_sameday->updateLocker($lockerObject, $locker['id']);
                }

                $remoteLockers[] = $lockerObject->getId();
            }
        } while ($page < $lockers->getPages());

        // Build array of local lockers.
        $localLockers = array_map(
            static function ($locker) {
                return array(
                    'id' => (int) $locker['id'],
                    'sameday_id' => (int) $locker['locker_id']
                );
            },
            $this->model_extension_shipping_sameday->getLockers($this->isTesting())
        );

        // Delete local lockers that aren't present in remote lockers anymore.
        foreach ($localLockers as $localLocker) {
            if (!in_array($localLocker['sameday_id'], $remoteLockers, true)) {
                $this->model_extension_shipping_sameday->deleteLocker($localLocker['id']);
            }
        }

        $this->updateLastSyncTimestamp();

        if ($redirectToPage) {
            $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
        }
    }

    /**
     * @throws SamedaySDKException
     */
    private function importServices($redirectToPage = false)
    {
        $this->model_extension_shipping_sameday->install();

        $sameday = new Sameday($this->samedayHelper->initClient());

        $samedayDBModel = $this->model_extension_shipping_sameday;

        $samedayDBModel->ensureSamedayServiceCodeColumn();

        $samedayDBModel->ensureSamedayServiceOptionalTaxColumn();

        $remoteServices = [];
        $page = 1;
        do {
            $request = new SamedayGetServicesRequest();
            $request->setPage($page++);
            try {
                $services = $sameday->getServices($request);
            } catch (\Exception $e) {
                $errorMessage = sprintf('Import Services error: %s', $e->getMessage());

                $this->session->data['error_warning'] = sprintf('%s &#8226; %s',
                    $this->session->data['error_warning'] ?? '',
                    $errorMessage
                );

                if ($redirectToPage) {
                    $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
                } else {
                    throw new \RuntimeException($errorMessage);
                }
            }

            foreach ($services->getServices() as $serviceObject) {
                $service = $this->model_extension_shipping_sameday->getServiceSameday($serviceObject->getId(), $this->isTesting());
                if (!$service) {
                    // Service not found, add it.
                    $this->model_extension_shipping_sameday->addService($serviceObject, $this->isTesting());
                } else {
                    // Service already exist, update it.
                    $this->model_extension_shipping_sameday->editService($service['id'], $serviceObject);
                }

                // Save as current sameday service.
                $remoteServices[] = $serviceObject->getId();
            }
        } while ($page <= $services->getPages());

        // Build array of local services.
        $localServices = array_map(
            static function ($service) {
                return array(
                    'id' => (int) $service['id'],
                    'sameday_id' => (int) $service['sameday_id']
                );
            },
            $this->model_extension_shipping_sameday->getServices($this->isTesting())
        );

        // Delete local services that aren't present in remote services anymore.
        foreach ($localServices as $localService) {
            if (!in_array($localService['sameday_id'], $remoteServices, true)) {
                $this->model_extension_shipping_sameday->deleteService($localService['id']);
            }
        }

        if ($redirectToPage) {
            $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
        }
    }

    private function importPickupPoint($redirectToPage = false)
    {
        $this->model_extension_shipping_sameday->install();

        $sameday = new Sameday($this->samedayHelper->initClient());

        $remotePickupPoints = [];
        $page = 1;
        do {
            $request = new SamedayGetPickupPointsRequest();
            $request->setPage($page++);
            try {
                $pickUpPoints = $sameday->getPickupPoints($request);
            } catch (\Exception $e) {
                $errorMessage = sprintf('Import Pickuppoint error: %s', $e->getMessage());

                $this->session->data['error_warning'] = sprintf('%s &#8226; %s',
                    $this->session->data['error_warning'] ?? '',
                    $errorMessage
                );

                if ($redirectToPage) {
                    $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
                } else {
                    throw new \RuntimeException($errorMessage);
                }
            }

            foreach ($pickUpPoints->getPickupPoints() as $pickupPointObject) {
                $pickupPoint = $this->model_extension_shipping_sameday->getPickupPointSameday($pickupPointObject->getId(), $this->isTesting());
                if (!$pickupPoint) {
                    // Pickup point not found, add it.
                    $this->model_extension_shipping_sameday->addPickupPoint($pickupPointObject, $this->isTesting());
                } else {
                    $this->model_extension_shipping_sameday->updatePickupPoint($pickupPointObject, $pickupPoint['id']);
                }

                // Save as current pickup points.
                $remotePickupPoints[] = $pickupPointObject->getId();
            }
        } while ($page <= $pickUpPoints->getPages());

        // Build array of local pickup points.
        $localPickupPoints = array_map(
            static function ($pickupPoint) {
                return array(
                    'id' => (int) $pickupPoint['id'],
                    'sameday_id' => (int) $pickupPoint['sameday_id']
                );
            },
            $this->model_extension_shipping_sameday->getPickupPoints($this->isTesting())
        );

        // Delete local pickup points that aren't present in remote pickup points anymore.
        foreach ($localPickupPoints as $localPickupPoint) {
            if (!in_array($localPickupPoint['sameday_id'], $remotePickupPoints, true)) {
                $this->model_extension_shipping_sameday->deletePickupPoint($localPickupPoint['id']);
            }
        }

        if ($redirectToPage) {
            $this->response->redirect($this->url->link('extension/shipping/sameday', $this->addToken(), true));
        }
    }

    /**
     * Refresh services.
     * @throws SamedaySDKException
     */
    public function serviceRefresh()
    {
        $this->importServices(true);
    }

    /**
     * Refresh Pick-up Point List
     * @throws SamedaySDKException
     */
    public function pickupPointRefresh()
    {
        $this->importPickupPoint(true);
    }

    /**
     * @throws SamedaySDKException
     */
    public function lockersRefresh()
    {
        $this->importLockers(true);
    }

    /**
     * @return void
     */
    private function updateLastSyncTimestamp()
    {
        $store_id = 0;
        $code = $this->model_extension_shipping_sameday->getKey('sameday');
        $key =  $this->model_extension_shipping_sameday->getKey('sameday_sync_lockers_ts');

        $time = time();

        $lastTimeSynced = $this->getConfig('sameday_sync_lockers_ts');

        if ($lastTimeSynced === null) {
            $value = $time;

            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '" . (int)$store_id . "', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "'");
        }

        $lastTs = $this->db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '" . (int)$store_id . "' AND `key` = '" .$this->db->escape($key) .  "'  AND `code` = '" . $this->db->escape($code) . "'")->row;
        $this->db->query('UPDATE '. DB_PREFIX ."setting SET value='{$this->db->escape($time)}' WHERE setting_id='{$this->db->escape($lastTs['setting_id'])}'");
    }

    public function service()
    {
        $service = $this->model_extension_shipping_sameday->getService($this->request->get['id']);

        $this->load->language('extension/shipping/sameday');
        $this->document->setTitle($this->language->get('heading_title_service'));
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validatePermissions()) {
            $this->model_extension_shipping_sameday->updateService($service['id'], $this->request->post);

            $this->session->data['error_success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/shipping/sameday/service', $this->addToken(array('id' => $service['id'])), true));
        }

        $data = $this->buildLanguage(array(
            'heading_title_service',
            'button_save',
            'button_cancel',

            'text_edit_service',
            'text_enabled',
            'text_disabled',
            'text_services_status_always',

            'entry_name',
            'entry_price',
            'entry_price_free',
            'entry_status',

            'from',
            'to',

            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'
        ));
        $data['text_edit_service'] = sprintf($this->language->get('text_edit_service'), $service['sameday_name']);

        $data['error_warning'] = $this->buildError('warning');
        $data['error_success'] = $this->buildError('success');

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->addToken(), true)
            ),
            array(
                'text' => $this->language->get('text_shipping'),
                'href' => $this->url->link($this->getRouteExtension(), $this->addToken(array('type' => 'shipping')), true)
            ),
            array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/shipping/sameday', $this->addToken(), true)
            ),
            array(
                'text' => $this->language->get('heading_title_service'),
                'href' => $this->url->link('extension/shipping/sameday/service', $this->addToken(array('id' => $service['id'])), true)
            )
        );

        $data['action'] = $this->url->link('extension/shipping/sameday/service', $this->addToken(array('id' => $service['id'])), true);
        $data['cancel'] = $this->url->link('extension/shipping/sameday', $this->addToken(), true);

        $data = array_merge(
            $data,
            $this->buildRequestService(
                array(
                    'name',
                    'price',
                    'price_free',
                    'status',
                ),
                $service
            )
        );

        $data['statuses'] = $this->getStatuses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/shipping/sameday_service', $data));
    }

    /**
     * Get data for order info template.
     *
     * @param $orderInfo
     *
     * @return array|null
     */
    public function info($orderInfo)
    {
        if (!$orderInfo) {
            return null;
        }

        $this->load->language('extension/shipping/sameday');
        $data = array(
            'EAWB_country_instance' => $this->samedayHelper::getEAWBInstanceUrlByCountry($this->getConfig('sameday_host_country')),
            'samedayAwb' => $this->language->get('text_sameday_awb'),
            'buttonAddAwb' => $this->language->get('text_button_add_awb'),
            'buttonDeleteAwb' => $this->language->get('text_button_delete_awb'),
            'buttonShowAwb' => $this->language->get('text_button_show_awb'),
            'buttonAwbHistory' => $this->language->get('text_button_show_awb_history'),
            'buttonAddAwbLink' => $this->url->link('extension/shipping/sameday/addAwb', $this->addToken(array('order_id' => $orderInfo['order_id'])), true),
            'buttonShowAwbPdf' => $this->url->link('extension/shipping/sameday/showAsPdf', $this->addToken(array('order_id' => $orderInfo['order_id'])), true),
            'buttonShowAwbHistory' => $this->url->link('extension/shipping/sameday/showAwbHistory', $this->addToken(array('order_id' => $orderInfo['order_id'])), true),
            'buttonDeleteAwbLink' => $this->url->link('extension/shipping/sameday/deleteAwb', $this->addToken(array('order_id' => $orderInfo['order_id'])), true)
        );

        $awb = $this->model_extension_shipping_sameday->getAwbForOrderId($orderInfo['order_id']);

        if ($awb) {
            $data['awb_number'] = $awb['awb_number'];
        }

        return $data;
    }

    public function addAwb()
    {
        /**
         * Set Title
         */
        $this->load->language('extension/shipping/sameday');
        $this->document->setTitle($this->language->get('heading_title_add_awb'));
        $this->load->model('sale/order');

        if (!isset($this->request->get['order_id']) || !($orderInfo = $this->model_sale_order->getOrder($this->request->get['order_id']))) {
            // Order id not sent or order not found.
            return new Action('error/not_found');
        }

        $shippingSamedayModel = $this->model_extension_shipping_sameday;

        $awb = $shippingSamedayModel->getAwbForOrderId($orderInfo['order_id']);

        if ($awb) {
            // Already generated.
            $this->response->redirect($this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderInfo['order_id'])), true));
        }

        $data = $this->buildRequestAwb(array(
            'sameday_insured_value',
            'sameday_package_number',
            'sameday_package_weight',
            'sameday_observation',
            'sameday_client_reference',
            'sameday_package_type',
            'sameday_pickup_point',
            'sameday_service',
            'sameday_locker_first_mile',
            'sameday_locker_id',
            'sameday_awb_payment',
            'sameday_third_party_pickup',
            'sameday_third_party_pickup_county',
            'sameday_third_party_pickup_city',
            'sameday_third_party_pickup_address',
            'sameday_third_party_pickup_name',
            'sameday_third_party_pickup_phone',
            'sameday_third_party_person_type',
            'sameday_third_party_person_company',
            'sameday_third_party_person_cif',
            'sameday_third_party_person_onrc',
            'sameday_third_party_person_bank',
            'sameday_third_party_person_iban'
        ));

        $data = array_merge($data, $this->buildLanguage(array(
            'text_create_awb',
            'text_type_person_individual',
            'text_type_person_business',
            'heading_title_add_awb',
            'heading_title_create_awb',
            'estimate_cost_msg',
            'estimate_cost_title',
            'awb_options',
            'estimate_cost',
            'button_cancel',

            'entry_insured_value',
            'entry_insured_value_title',
            'entry_packages_number',
            'entry_packages_number_title',
            'entry_calculated_weight',
            'entry_calculated_weight_title',
            'entry_package_dimension',
            'entry_client_reference',
            'entry_weight',
            'entry_width',
            'entry_length',
            'entry_height',
            'entry_observation',
            'entry_ramburs',
            'entry_pickup_point',
            'entry_pickup_point_title',
            'entry_locker_details',
            'entry_locker_details_title',
            'entry_locker_change',
            'entry_observation_title',
            'entry_client_reference_title',
            'entry_ramburs_title',
            'entry_package_type',
            'entry_package_type_title',
            'entry_awb_payment',
            'entry_service',
            'entry_service_title',
            'entry_locker_first_mile',
            'entry_locker_first_mile_title',
            'entry_awb_payment_title',
            'entry_third_party_pickup',
            'entry_third_party_pickup_title',
            'entry_third_party_pickup_county',
            'entry_third_party_pickup_county_title',
            'entry_third_party_pickup_city',
            'entry_third_party_pickup_city_title',
            'entry_third_party_pickup_name',
            'entry_third_party_pickup_name_title',
            'entry_third_party_pickup_address',
            'entry_third_party_pickup_address_title',
            'entry_third_party_pickup_phone',
            'entry_third_party_pickup_phone_title',
            'entry_third_party_person_type',
            'entry_third_party_person_type_title',
            'entry_third_party_person_company',
            'entry_third_party_person_company_title',
            'entry_third_party_person_cif',
            'entry_third_party_person_cif_title',
            'entry_third_party_person_onrc',
            'entry_third_party_person_onrc_title',
            'entry_third_party_person_bank',
            'entry_third_party_person_bank_title',
            'entry_third_party_person_iban',
            'entry_third_party_person_iban_title'
        )));

        $parts = explode('.', $orderInfo['shipping_code'], 5);
        $data['default_service_id'] = $parts[2] ?? null;

        $showLockerDetails = $this->toggleHtmlElement(false);
        $lockerDetails = '';
        $lockerPluginData = null;
        if (isset($parts[3], $parts[4])) {
            $lockerDetails = $parts[4];
            $lockerPluginData = [
                'lockerId' => $parts[3],
                'lockerAddress' => $parts[4],
                'country' => $orderInfo['shipping_iso_code_2'],
                'city' => $orderInfo['shipping_city'] ?? null,
                'apiUsername' => $this->getConfig('sameday_username'),
            ];

            $showLockerDetails = $this->toggleHtmlElement(true);
        }

        $showPDO = $this->toggleHtmlElement(false);
        $currentService = $shippingSamedayModel->getServiceSameday(
            (int) ($data['default_service_id'] ?? null),
            $this->isTesting()
        );
        if (isset($currentService['service_optional_taxes']) && $this->isServiceEligibleToPDO($currentService['service_optional_taxes'])) {
            $showPDO = $this->toggleHtmlElement(true);
        }

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validateFormBeforeAwbGeneration()) {
            $postRequestData = $this->request->post;

            if ('' === $postRequestData['sameday_locker_id']
                || '' === $postRequestData['sameday_locker_address']
                || $this->samedayHelper::LOCKER_NEXT_DAY_CODE !== $shippingSamedayModel->getServiceSameday(
                    (int) $postRequestData['sameday_service'],
                    $this->isTesting())['sameday_code'] ?? null
                )
            {
                $postRequestData['sameday_locker_id'] = null;
                $postRequestData['sameday_locker_address'] = null;
            }

            $params = array_merge($postRequestData, $orderInfo);
            $service = $shippingSamedayModel->getServiceSameday((int) ($params['sameday_service'] ?? null), $this->isTesting());

            $postAwb = $this->postAwb($params);

            $awb = $postAwb['awb'];
            $errors = $postAwb['errors'];

            if (isset($awb)) {
                $shippingSamedayModel->saveAwb(array(
                    'order_id' => $orderInfo['order_id'],
                    'awb_number' => $awb->getAwbNumber(),
                    'parcels' => serialize($awb->getParcels()),
                    'awb_cost' =>  $awb->getCost()
                ));

                $shippingSamedayModel->updateShippingMethodAfterPostAwb(
                    $orderInfo['order_id'],
                    $service,
                    $postRequestData['sameday_locker_id'],
                    $postRequestData['sameday_locker_address']
                );

                // Redirect to order page.
                $this->response->redirect($this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderInfo['order_id'])), true));
            } elseif (isset($errors)) {
                $data['awb_errors'] = [];

                foreach ($errors as $error) {
                    foreach ($error['errors'] as $message) {
                        $data['awb_errors'][] = implode('.', $error['key']) . ': ' . $message;
                    }
                }
            }
        }

        if (!empty($this->error)) {
            foreach ($this->error as $key => $value) {
                $data[$key] = $this->buildError($key);
            }

            $data['all_errors'] = $this->error;
        }

        $data['packageTypes'] = array(
            array(
                'name' => $this->language->get('text_package_type_package'),
                'value' => \Sameday\Objects\Types\PackageType::PARCEL
            ),
            array(
                'name' => $this->language->get('text_package_type_envelope'),
                'value' => \Sameday\Objects\Types\PackageType::ENVELOPE
            ),
            array(
                'name' => $this->language->get('text_package_type_large_package'),
                'value' => \Sameday\Objects\Types\PackageType::LARGE
            )
        );

        $data['awbPaymentsType'] = array(
            array(
                'name' => $this->language->get('text_client'),
                'value' => \Sameday\Objects\Types\AwbPaymentType::CLIENT
            )
        );

        $repayment = 0;
        if ($orderInfo['payment_code'] === $this->samedayHelper::CASH_ON_DELIVERY_CODE) {
            $repayment = $this->currency->format(
                $orderInfo['total'],
                $orderInfo['currency_code'],
                $orderInfo['currency_value'],
                false
            );
        }

        $availableServices = [];
        $services = $shippingSamedayModel->getServices($this->getConfig('sameday_testing'));
        foreach ($services as $service) {
            if ($service['status'] > 0) {
                $service['service_eligible_to_locker'] = $this->toggleHtmlElement(false);
                if (isset($service['sameday_code'])) {
                    $service['service_eligible_to_locker'] = $service['sameday_code'] === $this->samedayHelper::LOCKER_NEXT_DAY_CODE
                        ? $this->toggleHtmlElement(true)
                        : $this->toggleHtmlElement(false)
                    ;
                }

                $service['service_eligible_to_pdo'] = $this->toggleHtmlElement(false);
                if (isset($service['service_optional_taxes'])) {
                    $service['service_eligible_to_pdo'] = $this->isServiceEligibleToPDO($service['service_optional_taxes'])
                        ? $this->toggleHtmlElement(true)
                        : $this->toggleHtmlElement(false)
                    ;
                }

                $availableServices[] = $service;
            }
        }

        $orderCurrency = $orderInfo['currency_code'];
        $destCurrency = $this->samedayHelper::SAMEDAY_ELIGIBLE_CURRENCIES[$orderInfo['shipping_iso_code_2']];

        $repaymentCurrencyAlert = null;
        if ($orderCurrency !== $destCurrency) {
            $repaymentCurrencyAlert = sprintf(
                "Be aware that the intended currency is %s but the Repayment value is expressed in %s. 
                Please consider a conversion !!",
                $destCurrency,
                $orderCurrency
            );
        }

        $data['sameday_ramburs'] = $repayment;
        $data['sameday_currency'] = $orderInfo['currency_code'];
        $data['repaymentCurrencyAlert'] = $repaymentCurrencyAlert;
        $data['sameday_client_reference'] = $orderInfo['order_id'];
        $data['pickupPoints'] = $shippingSamedayModel->getPickupPoints($this->getConfig('sameday_testing'));
        $data['services'] = $availableServices;
        $data['lockerDetails'] = $lockerDetails;
        $data['lockerPluginData'] = $lockerPluginData;
        $data['showLockerDetails'] = $showLockerDetails;
        $data['showPDO'] = $showPDO;
        $data['pdo_code'] = $this->samedayHelper::SERVICE_OPTIONAL_TAX_PDO_CODE;
        $data['calculated_weight'] = $this->calculatePackageWeight($orderInfo['order_id']);
        $data['counties'] = $shippingSamedayModel->getCounties(
            $this->getConfig('sameday_host_country') ?? $this->samedayHelper::API_HOST_LOCALE_RO
        );

        /*
         * Breadcrumbs
         */
        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->addToken(), true),
                'separator' => false
            ),
            array(
                'text' => $this->language->get('text_orders'),
                'href' => $this->url->link('sale/order', $this->addToken(), true)
            ),
            array(
                'text' => $this->language->get('text_order_info'),
                'href' => $this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderInfo['order_id'])), true)
            ),
            array(
                'text' => $this->language->get('text_add_awb'),
                'href' => $this->url->link('extension/shipping/sameday/createAwb', $this->addToken(array('order_id' => $orderInfo['order_id'])), true)
            )
        );

        /*
        * Actions
        */
        $data['estimate_cost_href'] = $this->url->link('extension/shipping/sameday/estimateCost');
        $data['action'] = $this->url->link('extension/shipping/sameday/addAwb', $this->addToken(array('order_id' => $orderInfo['order_id'])), true);
        $data['cancel'] = $this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderInfo['order_id'])), true);

        /*
         * Main Layout
         */
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $this->response->setOutput($this->load->view('extension/shipping/sameday_add_awb', $data));
    }

    /**
     * @throws \Sameday\Exceptions\SamedayOtherException
     * @throws SamedaySDKException
     * @throws \Sameday\Exceptions\SamedayServerException
     * @throws \Sameday\Exceptions\SamedayAuthenticationException
     * @throws \Sameday\Exceptions\SamedayAuthorizationException
     * @throws \Sameday\Exceptions\SamedayNotFoundException
     */
    public function showAwbHistory()
    {
        $this->load->language('extension/shipping/sameday');
        $this->document->setTitle($this->language->get('heading_title_awb_history'));

        $awb = $this->model_extension_shipping_sameday->getAwbForOrderId($this->request->get['order_id']);

        if (!$awb) {
            return new Action('error/not_found');
        }

        $orderId = (int) $awb['order_id'];

        /*
        * Labels
        */
        $data = $this->buildLanguage(array(
            'text_awb_sync',
            'heading_title',
            'text_summary',
            'text_history',
            'awb_history_title',
            'button_cancel',
            'column_parcel_number',
            'column_parcel_weight',
            'column_delivered',
            'column_delivery_attempts',
            'column_is_picked_up',
            'column_picked_up_at',
            'column_status',
            'column_status_label',
            'column_status_state',
            'column_status_date',
            'column_county',
            'column_transit_location',
            'column_reason'
        ));

        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->addToken(), true),
                'separator' => false
            ),
            array(
                'text' => $this->language->get('text_orders'),
                'href' => $this->url->link('sale/order', $this->addToken(), true)
            ),
            array(
                'text' => $this->language->get('text_order_info'),
                'href' => $this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderId)), true)
            ),
            array(
                'text' => 'AWB',
                'href' => $this->url->link('extension/shipping/sameday/showAwbStatus', $this->addToken(array('order_id' => $orderId)), true)
            )
        );

        /*
         * Actions
         */
        $data['action'] = $this->url->link('extension/shipping/sameday', $this->addToken(), true);
        $data['cancel'] = $this->url->link('sale/order/info', $this->addToken(array('order_id' => $awb['order_id'])), true);

        /*
         * Main Layout
         */
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        if(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
            // Not an ajax request, return main page.
            $this->response->setOutput($this->load->view('extension/shipping/sameday_awb_history_status', $data));

            return null;
        }

        // Build ajax html.
        $sameday = new Sameday($this->samedayHelper->initClient());

        /** @var \Sameday\Objects\PostAwb\ParcelObject[] $parcels */
        $parcels = unserialize($awb['parcels'], ['']);
        foreach ($parcels as $parcel) {
            $parcelStatus = $sameday->getParcelStatusHistory(new \Sameday\Requests\SamedayGetParcelStatusHistoryRequest($parcel->getAwbNumber()));
            $this->model_extension_shipping_sameday->refreshPackageHistory(
                $awb['order_id'],
                $parcel->getAwbNumber(),
                $parcelStatus->getSummary(),
                $parcelStatus->getHistory(),
                $parcelStatus->getExpeditionStatus()
            );
        }

        $data['packages'] = $this->model_extension_shipping_sameday->getPackagesForOrderId($awb['order_id']);

        $this->response->setOutput($this->load->view('extension/shipping/sameday_awb_history_status_refresh', $data));

        return null;
    }

    /**
     * @throws SamedaySDKException
     * @throws SamedayBadRequestException
     * @throws \Sameday\Exceptions\SamedayServerException
     * @throws \Sameday\Exceptions\SamedayAuthenticationException
     * @throws \Sameday\Exceptions\SamedayAuthorizationException
     * @throws \Sameday\Exceptions\SamedayNotFoundException
     */
    public function showAsPdf()
    {
        $orderId = (int) $this->request->get['order_id'];
        $awb = $this->model_extension_shipping_sameday->getAwbForOrderId($orderId);

        if (!$awb) {
            return new Action('error/not_found');
        }

        header('Content-type: application/pdf');
        header("Cache-Control: no-cache");
        header("Pragma: no-cache");

        $sameday = new Sameday($this->samedayHelper->initClient());

        $content = $sameday->getAwbPdf(new SamedayGetAwbPdfRequest($awb['awb_number'], new AwbPdfType(AwbPdfType::A4)));
        echo $content->getPdf();

        exit;
    }

    /**
     * @param $orderInfo
     * @return array
     * @throws \Sameday\Exceptions\SamedayAuthenticationException
     * @throws \Sameday\Exceptions\SamedayAuthorizationException
     * @throws \Sameday\Exceptions\SamedayNotFoundException
     * @throws \Sameday\Exceptions\SamedayOtherException
     * @throws SamedaySDKException
     * @throws \Sameday\Exceptions\SamedayServerException
     */
    private function postAwb($params)
    {
        $parcelDimensions = [];
        foreach ($this->request->post['sameday_package_weight'] as $k => $weight) {
            $parcelDimensions[] = new \Sameday\Objects\ParcelDimensionsObject(
                $weight,
                $this->request->post['sameday_package_width'][$k],
                $this->request->post['sameday_package_length'][$k],
                $this->request->post['sameday_package_height'][$k]
            );
        }

        $companyObject = null;
        if (strlen($params['payment_company'])) {
            $companyObject = new \Sameday\Objects\PostAwb\Request\CompanyEntityObject(
                $params['payment_company'],
                '',
                '',
                '',
                ''
            );
        }

        $thirdPartyPickUp = null;
        if ($params['sameday_third_party_pickup']) {
            $thirdPartyCompany = null;
            if ($params['sameday_third_party_person_type']) {
                $thirdPartyCompany = new \Sameday\Objects\PostAwb\Request\CompanyEntityObject(
                    $params['sameday_third_party_person_company'],
                    $params['sameday_third_party_person_cif'],
                    $params['sameday_third_party_person_onrc'],
                    $params['sameday_third_party_person_bank'],
                    $params['sameday_third_party_person_iban']
                );
            }

            $thirdPartyPickUp = new ThirdPartyPickupEntityObject(
                $params['sameday_third_party_pickup_county'],
                $params['sameday_third_party_pickup_city'],
                $params['sameday_third_party_pickup_address'],
                $params['sameday_third_party_pickup_name'],
                $params['sameday_third_party_pickup_phone'],
                $thirdPartyCompany
            );
        }

        $address = trim($params['shipping_address_1'] . ' ' . $params['shipping_address_2']);
        $destCurrency = $this->samedayHelper::SAMEDAY_ELIGIBLE_CURRENCIES[$params['shipping_iso_code_2']];

        $serviceTaxes = [];
        if (isset($params['sameday_locker_first_mile'])) {
            $serviceTaxes[] = $params['sameday_locker_first_mile'];
        }

        $request = new SamedayPostAwbRequest(
            (int) ($params['sameday_pickup_point'] ?? null),
            null,
            new \Sameday\Objects\Types\PackageType($params['sameday_package_type']),
            $parcelDimensions,
            (int) ($params['sameday_service'] ?? null),
            new \Sameday\Objects\Types\AwbPaymentType($params['sameday_awb_payment']),
            new \Sameday\Objects\PostAwb\Request\AwbRecipientEntityObject(
                $params['shipping_city'],
                $params['shipping_zone'],
                $address,
                $params['shipping_firstname'] . ' ' . $params['shipping_lastname'],
                $params['telephone'],
                $params['email'],
                $companyObject,
                $params['shipping_postcode']
            ),
            $params['sameday_insured_value'],
            $params['sameday_ramburs'],
            new \Sameday\Objects\Types\CodCollectorType(\Sameday\Objects\Types\CodCollectorType::CLIENT),
            $thirdPartyPickUp,
            $serviceTaxes,
            null,
            $params['sameday_client_reference'],
            $params['sameday_observation'],
            '',
            '',
            null,
            $params['sameday_locker_id'],
            $destCurrency
        );

        $sameday = new Sameday($this->samedayHelper->initClient());

        try{
            $awb = $sameday->postAwb($request);
        }  catch (SamedayBadRequestException $e) {
            $errors = $e->getErrors();
        } catch (SamedaySDKException $e) {
            $errors[] = [
                'key' => ['SDK Error'],
                'errors' => [$e->getMessage()],
            ];

        }

        return array(
            'awb' => isset($awb) ? $awb : null,
            'errors' => isset($errors) ? $errors : null
        );
    }

    /**
     * @param int $orderId
     *
     * @return float|int
     */
    private function calculatePackageWeight($orderId)
    {
        $items = $this->getItemsByOrderId($orderId);
        $totalWeight = 0 ;
        foreach ($items as $item) {
            $totalWeight += round($item['product_info']['weight'] * $item['quantity'], 2);
        }

        return $totalWeight;
    }

    /**
     * estimateCost
     */
    public function estimateCost()
    {
        $this->load->model('sale/order');
        $this->load->language('extension/shipping/sameday');

        $params = $this->request->post;
        $order_id = $params['order_id'];

        $orderInfo = $this->model_sale_order->getOrder($order_id);

        if (!strlen($params['sameday_insured_value'])) {
            $return['errors'][] = $this->language->get('error_insured_value_cost');
        }

        $parcelDimensions = [];
        foreach ($params['sameday_package_weight'] as $k => $weight) {

            if (!strlen($weight) || $weight < 1) {
                $return['errors'][] = $this->language->get('error_weight_cost');
            }

            $parcelDimensions[] = new \Sameday\Objects\ParcelDimensionsObject(
                $weight,
                $params['sameday_package_width'][$k],
                $params['sameday_package_length'][$k],
                $params['sameday_package_height'][$k]
            );
        }

        if (! isset($return['errors'])) {
            $serviceTaxes = [];
            if (isset($params['sameday_locker_first_mile'])) {
                $serviceTaxes[] = $params['sameday_locker_first_mile'];
            }

            $destCurrency = $this->samedayHelper::SAMEDAY_ELIGIBLE_CURRENCIES[$orderInfo['shipping_iso_code_2']];

            $estimateCostRequest = new \Sameday\Requests\SamedayPostAwbEstimationRequest(
                $params['sameday_pickup_point'],
                null,
                new \Sameday\Objects\Types\PackageType(
                    $params['sameday_package_type']
                ),
                $parcelDimensions,
                $params['sameday_service'],
                new \Sameday\Objects\Types\AwbPaymentType(
                    $params['sameday_awb_payment']
                ),
                new \Sameday\Objects\PostAwb\Request\AwbRecipientEntityObject(
                    ucwords(strtolower($orderInfo['shipping_city'])) !== 'Bucuresti' ? $orderInfo['shipping_city'] : 'Sector 1',
                    $orderInfo['shipping_zone'],
                    $orderInfo['shipping_address_1'],
                    null,
                    null,
                    null,
                    null,
                    $orderInfo['shipping_postcode']
                ),
                $params['sameday_insured_value'],
                $params['sameday_ramburs'],
                null,
                $serviceTaxes,
                $destCurrency
            );

            $sameday =  new \Sameday\Sameday($this->samedayHelper->initClient());
            $return = [];

            try {
                $estimation = $sameday->postAwbEstimation($estimateCostRequest);
                $cost = $estimation->getCost();
                $currency = $estimation->getCurrency();

                $return['success'] = sprintf($this->language->get('estimated_cost_success_message'), $cost, $currency);
            } catch (SamedayBadRequestException $exception) {
                $errors = $exception->getErrors();
            } catch (SamedaySDKException $exception) {
                $errors[] = [
                    'key' => ['SDK Error'],
                    'errors' => [$exception->getMessage()],
                ];
            }

            if (isset($errors)) {
                foreach ($errors as $error) {
                    foreach ($error['errors'] as $message) {
                        $return['errors'][] = implode('.', $error['key']) . ': ' . $message;
                    }
                }
            }
        }

        $this->response->setOutput(json_encode($return));
    }

    /**
     * @param int $orderId
     *
     * @return mixed
     */
    private function getItemsByOrderId($orderId)
    {
        $this->load->model('sale/order');
        $this->load->model('catalog/product');

        $items = $this->model_sale_order->getOrderProducts($orderId);

        foreach ($items as $item => $value) {
            $items[$item]['product_info'] = $this->model_catalog_product->getProduct($value['product_id']);
        }

        return $items;
    }

    public function deleteAwb()
    {
        $orderId = $this->request->get['order_id'];
        $awb = $this->model_extension_shipping_sameday->getAwbForOrderId($orderId);
        $sameday = new Sameday($this->samedayHelper->initClient());

        if ($awb) {
            try {
                $sameday->deleteAwb(new \Sameday\Requests\SamedayDeleteAwbRequest($awb['awb_number']));
                $this->model_extension_shipping_sameday->deleteAwb($awb['awb_number']);
            } catch (\Exception $e) { }
        }

        $this->response->redirect($this->url->link('sale/order/info', $this->addToken(array('order_id' => $orderId)), true));
    }

    /**
     * @return bool
     */
    private function validateFormBeforeAwbGeneration()
    {
        if (!strlen($this->request->post['sameday_insured_value']) ||
            $this->request->post['sameday_insured_value'] < 0 )  {
            $this->error['error_insured_val'] = $this->language->get('error_insured_value');
        }

        $packageWeights = $this->request->post['sameday_package_weight'];
        foreach ($packageWeights as $weight) {
            if (!strlen($weight)) {
                $this->error['error_weight'] = $this->language->get('error_weight');
            }
        }

        if ($this->request->post['sameday_third_party_pickup']) {
            $thirdPartyMandatoryFields = array(
                'sameday_third_party_pickup_county',
                'sameday_third_party_pickup_city',
                'sameday_third_party_pickup_address',
                'sameday_third_party_pickup_name',
                'sameday_third_party_pickup_phone'
            );
            foreach ($thirdPartyMandatoryFields as $field) {
                if (!strlen($this->request->post[$field])) {
                    $error = str_replace('sameday_','error_', $field);
                    $entry = str_replace('sameday_','entry_', $field);
                    $this->error[$error] = sprintf(
                        $this->language->get('error_third_party_pickup_mandatory_fields'),
                        $this->language->get($entry));
                }
            }
        }

        if ($this->request->post['sameday_third_party_person_type']) {
            $personTypeMandatoryFields = array(
                'sameday_third_party_person_company',
                'sameday_third_party_person_cif',
                'sameday_third_party_person_onrc',
                'sameday_third_party_person_bank',
                'sameday_third_party_person_iban'
            );
            foreach ($personTypeMandatoryFields as $field) {
                if (!strlen($this->request->post[$field])) {
                    $error = str_replace('sameday_','error_', $field);
                    $entry = str_replace('sameday_','entry_', $field);
                    $this->error[$error] = sprintf(
                        $this->language->get('error_third_party_person_mandatory_fields'),
                        $this->language->get($entry));
                }
            }
        }

        return !$this->error;
    }

    private function validatePermissions()
    {
        if (!$this->user->hasPermission('modify', 'extension/shipping/sameday')) {
            $this->error['warning'] = $this->language->get('error_permission');

            return false;
        }

        return true;
    }

    /**
     * @return bool
     * @throws SamedaySDKException
     */
    private function validate(): bool
    {
        if (!$this->validatePermissions()) {
            return false;
        }

        $needLogin = false;

        $username = $this->getConfig('sameday_username');
        if ($this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_username')] !== $username) {
            // Username changed.
            $username = $this->request->post[$this->model_extension_shipping_sameday->getKey('sameday_username')];
            $needLogin = true;
        }

        $password = $this->getConfig('sameday_password');
        if ('' !== $newPassword = $this->model_extension_shipping_sameday->sanitizeInput(
            $_POST[$this->model_extension_shipping_sameday->getKey('sameday_password')])
        ) {
            // Password updated.
            $password = $newPassword;
            $needLogin = true;
        }

        if ($needLogin) {
            // Check if login is valid.
            $isLogged = false;
            $envModes = $this->samedayHelper::getEnvModes();
            foreach ($envModes as $hostCountry => $envModesByHosts) {
                if ($isLogged === true) {
                    break;
                }

                foreach ($envModesByHosts as $key => $apiUrl) {
                    $sameday = $this->samedayHelper->initClient(
                        $username,
                        $password,
                        $apiUrl
                    );

                    try {
                        if ($sameday->login()) {
                            $isTesting = (int) ($this->samedayHelper::API_DEMO === $key);
                            $this->testing = $isTesting;
                            $this->hostCountry = $hostCountry;
                            $isLogged = true;

                            break;
                        }
                    } catch (Exception $exception) {
                        continue;
                    }
                }
            }

            if (!$isLogged) {
                $this->error['warning'] = $this->language->get('error_username_password');

                return false;
            }
        }

        return !$this->error;
    }

    private function buildLanguage(array $keys)
    {
        $entries = array();
        foreach ($keys as $key) {
            $entries[$key] = $this->language->get($key);
        }

        return $entries;
    }

    private function buildRequest(array $keys): array
    {
        $entries = array();
        foreach ($keys as $key) {
            $requestKey = sprintf("%ssameday_%s", $this->model_extension_shipping_sameday->getPrefix(), $key);
            $entries["sameday_$key"] = $this->request->post[$requestKey] ?? $this->getConfig("sameday_$key");
        }

        return $entries;
    }

    private function buildRequestService($keys, $service): array
    {
        $entries = array();
        foreach ($keys as $key) {
            $entries[$key] = $this->request->post[$key] ?? $service[$key];
        }

        return $entries;
    }

    private function buildRequestAwb($keys): array
    {
        $entries = array();
        foreach ($keys as $key) {
            $entries[$key] = $this->request->post[$key] ?? '';
        }

        return $entries;
    }

    private function buildError($key)
    {
        if (isset($this->error[$key])) {
            return $this->error[$key];
        }

        if (isset($this->session->data["error_$key"])) {
            $message = $this->session->data["error_$key"];
            unset($this->session->data["error_$key"]);

            return $message;
        }

        return '';
    }

    private function getStatuses()
    {
        $lang = $this->buildLanguage([
            'text_disabled',
            'text_services_status_always'
        ]);

        return array(
            array(
                'value' => 0,
                'text' => $lang['text_disabled']
            ),
            array(
                'value' => 1,
                'text' => $lang['text_services_status_always']
            )
        );
    }

    private function isServiceEligibleToPDO($serviceOptionalTaxes): bool
    {
        $serviceOptionalTaxes = json_decode($serviceOptionalTaxes, true);
        foreach ($serviceOptionalTaxes as $tax) {
            if ($tax['code'] === $this->samedayHelper::SERVICE_OPTIONAL_TAX_PDO_CODE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $parts
     *
     * @return array
     */
    private function addToken(array $parts = array())
    {
        if (isset($this->session->data['token'])) {
            return array_merge($parts, array('token' => $this->session->data['token']));
        }

        if (isset($this->session->data['user_token'])) {
            return array_merge($parts, array('user_token' => $this->session->data['user_token']));
        }

        return $parts;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    private function getConfig($key)
    {
        return $this->model_extension_shipping_sameday->getConfig($key);
    }

    private function getRouteExtension()
    {
        if (strpos(VERSION, '2') === 0) {
            return 'extension/extension';
        }

        return 'marketplace/extension';
    }

    private function toggleHtmlElement(bool $isShow): string
    {
        return $isShow === true ? self::TOGGLE_HTML_ELEMENT['show'] : self::TOGGLE_HTML_ELEMENT['hide'];
    }
}
