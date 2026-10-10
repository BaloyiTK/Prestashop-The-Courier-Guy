<?php
if (!defined('_PS_VERSION_')) { exit; }

class CourierGuyDelivery extends CarrierModule
{
    const SELECTION_TABLE = 'courierguydelivery_selection';
    const CARRIER_CONFIG = 'COURIERGUYDELIVERY_CARRIER';

    const SERVICES = [
        'pudo_locker_locker' => [
            'label' => 'PUDO Locker-to-Locker',
            'price' => 60.00,
            'speed' => '1–3 business days',
            'scope' => 'domestic',
            'description' => 'Self-service collection at a destination PUDO locker.',
            'requires_locker' => true,
        ],
        'economy_road' => [
            'label' => 'Economy Road Freight',
            'price' => 95.00,
            'speed' => '2–4 business days',
            'scope' => 'domestic',
            'description' => 'Budget-friendly road delivery for less time-sensitive parcels.',
            'requires_locker' => false,
        ],
        'pudo_locker_door' => [
            'label' => 'PUDO Locker-to-Door',
            'price' => 99.00,
            'speed' => '1–3 business days',
            'scope' => 'domestic',
            'description' => 'PUDO dispatch with delivery to the customer’s normal delivery address.',
            'requires_locker' => false,
        ],
        'overnight_express' => [
            'label' => 'Overnight Express',
            'price' => 125.00,
            'speed' => 'Next business day by 17:00',
            'scope' => 'domestic',
            'description' => 'Urgent domestic delivery on supported national routes.',
            'requires_locker' => false,
        ],
        'same_day_metro' => [
            'label' => 'Same-Day Metro',
            'price' => 140.00,
            'speed' => 'Same day · book before 11:00',
            'scope' => 'domestic',
            'description' => 'Metro service for supported areas such as JHB, CPT, DBN and PTA.',
            'requires_locker' => false,
        ],
        'international_express' => [
            'label' => 'International Express',
            'price' => 450.00,
            'speed' => '3–7 business days',
            'scope' => 'international',
            'description' => 'Outbound international express delivery. Final availability and charges depend on destination.',
            'requires_locker' => false,
        ],
    ];

    public function __construct()
    {
        $this->name = 'courierguydelivery';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Kaziara Solutions';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('The Courier Guy Delivery');
        $this->description = $this->l('One compact Courier Guy carrier with service-tier pricing and conditional PUDO locker capture.');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        if (!parent::install() || !$this->installDb()) {
            return false;
        }

        foreach (['displayCarrierExtraContent', 'actionValidateStepComplete', 'actionValidateOrder', 'displayOrderConfirmation', 'displayAdminOrderMainBottom', 'actionCarrierUpdate'] as $hook) {
            if (!$this->registerHook($hook)) {
                $this->uninstall();
                return false;
            }
        }

        return $this->installCarrier();
    }

    public function uninstall()
    {
        $carrierIds = $this->moduleCarrierIds();
        $configured = (int) Configuration::get(self::CARRIER_CONFIG);
        if ($configured > 0) {
            $carrierIds[] = $configured;
        }

        if (!parent::uninstall()) {
            return false;
        }

        $db = Db::getInstance();
        try {
            if ($carrierIds) {
                $ids = implode(',', array_map('intval', array_unique($carrierIds)));
                $db->execute('UPDATE `'._DB_PREFIX_.'carrier` SET `active` = 0, `deleted` = 1 WHERE `id_carrier` IN ('.$ids.')');
            }
            $db->execute("UPDATE `"._DB_PREFIX_."carrier` SET `active` = 0, `deleted` = 1 WHERE `external_module_name` = 'courierguydelivery'");
        } catch (\Throwable $e) {
        }

        try { Configuration::deleteByName(self::CARRIER_CONFIG); } catch (\Throwable $e) {}
        try { $db->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.self::SELECTION_TABLE.'`'); } catch (\Throwable $e) {}

        return true;
    }

    private function installDb()
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.self::SELECTION_TABLE.'` ('
            .'`id_cart` INT UNSIGNED NOT NULL,'
            .'`id_order` INT UNSIGNED NOT NULL DEFAULT 0,'
            .'`service` VARCHAR(40) NOT NULL DEFAULT \'\','
            .'`locker` VARCHAR(255) NOT NULL DEFAULT \'\','
            .'PRIMARY KEY (`id_cart`),'
            .'KEY `id_order` (`id_order`)'
            .') ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4'
        );
    }

    private function zones()
    {
        $ids = [];
        foreach (Zone::getZones(true) as $zone) {
            if (!empty($zone['active'])) {
                $ids[] = (int) $zone['id_zone'];
            }
        }
        if (!$ids) {
            foreach (Zone::getZones(false) as $zone) {
                $ids[] = (int) $zone['id_zone'];
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public function installCarrier()
    {
        $ctx = Context::getContext();
        $db = Db::getInstance();
        $zones = $this->zones();
        if (!$zones) {
            return false;
        }

        $carrier = new Carrier();
        $carrier->name = 'The Courier Guy';
        $carrier->active = 1;
        $carrier->deleted = 0;
        $carrier->shipping_handling = 0;
        $carrier->range_behavior = 1;
        $carrier->is_module = 1;
        $carrier->shipping_external = 1;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = 1;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
        $carrier->max_width = 0;
        $carrier->max_height = 0;
        $carrier->max_depth = 0;
        $carrier->max_weight = 0;
        $carrier->grade = 0;
        $carrier->url = '';

        foreach (Language::getLanguages(false) as $language) {
            $carrier->delay[(int) $language['id_lang']] = 'Choose your Courier Guy service';
        }

        if (!$carrier->add()) {
            return false;
        }

        Configuration::updateValue(self::CARRIER_CONFIG, (int) $carrier->id);

        foreach (Group::getGroups((int) $ctx->language->id) as $group) {
            $db->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $group['id_group'],
            ], false, true, Db::INSERT_IGNORE);
        }

        foreach ($zones as $zoneId) {
            $db->insert('carrier_zone', [
                'id_carrier' => (int) $carrier->id,
                'id_zone' => (int) $zoneId,
            ], false, true, Db::INSERT_IGNORE);
        }

        $range = new RangeWeight();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = 0;
        $range->delimiter2 = 10000;
        if (!$range->add()) {
            return false;
        }

        foreach ($zones as $zoneId) {
            if (!$db->insert('delivery', [
                'id_shop' => (int) $ctx->shop->id,
                'id_shop_group' => (int) $ctx->shop->id_shop_group,
                'id_carrier' => (int) $carrier->id,
                'id_range_price' => 0,
                'id_range_weight' => (int) $range->id,
                'id_zone' => (int) $zoneId,
                'price' => 60.00,
            ])) {
                return false;
            }
        }

        $logo = $this->local_path.'views/img/courier-guy-logo.jpg';
        if (defined('_PS_SHIP_IMG_DIR_') && is_file($logo)) {
            @copy($logo, _PS_SHIP_IMG_DIR_.(int) $carrier->id.'.jpg');
        }

        return true;
    }

    private function moduleCarrierIds()
    {
        $rows = Db::getInstance()->executeS(
            "SELECT `id_carrier` FROM `"._DB_PREFIX_."carrier` WHERE `external_module_name` = 'courierguydelivery'"
        );
        $ids = [];
        foreach ((array) $rows as $row) {
            $ids[] = (int) $row['id_carrier'];
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function isOurCarrier($idCarrier)
    {
        return (int) $idCarrier > 0 && (int) $idCarrier === (int) Configuration::get(self::CARRIER_CONFIG);
    }

    private function hookCarrierId($carrier)
    {
        if (is_object($carrier) && isset($carrier->id)) {
            return (int) $carrier->id;
        }
        if (is_array($carrier)) {
            return (int) ($carrier['id'] ?? $carrier['id_carrier'] ?? 0);
        }
        return 0;
    }

    private function deliveryCountryIso()
    {
        try {
            if (isset($this->context->cart) && (int) $this->context->cart->id_address_delivery > 0) {
                $address = new Address((int) $this->context->cart->id_address_delivery);
                if (Validate::isLoadedObject($address)) {
                    $country = new Country((int) $address->id_country);
                    if (Validate::isLoadedObject($country) && !empty($country->iso_code)) {
                        return strtoupper((string) $country->iso_code);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return 'ZA';
    }

    private function availableServices()
    {
        $international = $this->deliveryCountryIso() !== 'ZA';
        $services = [];
        foreach (self::SERVICES as $key => $service) {
            if ($international && $service['scope'] === 'international') {
                $services[$key] = $service;
            } elseif (!$international && $service['scope'] === 'domestic') {
                $services[$key] = $service;
            }
        }
        return $services;
    }

    private function validService($service, $availableOnly = false)
    {
        $service = (string) $service;
        if (!isset(self::SERVICES[$service])) {
            return '';
        }
        if ($availableOnly && !isset($this->availableServices()[$service])) {
            return '';
        }
        return $service;
    }

    private function requiresLocker($service)
    {
        return $service !== '' && !empty(self::SERVICES[$service]['requires_locker']);
    }

    private function postedService()
    {
        return $this->validService(Tools::getValue('courierguydelivery_service', ''), true);
    }

    private function postedLocker()
    {
        return trim((string) Tools::getValue('courierguydelivery_locker', ''));
    }

    private function savedSelection($cartId)
    {
        if ((int) $cartId <= 0) {
            return ['service' => '', 'locker' => ''];
        }
        $row = Db::getInstance()->getRow(
            'SELECT `service`, `locker` FROM `'._DB_PREFIX_.self::SELECTION_TABLE.'` WHERE `id_cart`='.(int) $cartId
        );
        return [
            'service' => $this->validService($row['service'] ?? '', true),
            'locker' => (string) ($row['locker'] ?? ''),
        ];
    }

    private function saveSelection($cartId, $service, $locker, $orderId = 0)
    {
        if ((int) $cartId <= 0) {
            return false;
        }
        $service = $this->validService($service, true);
        $locker = $this->requiresLocker($service) ? trim((string) $locker) : '';
        return Db::getInstance()->execute(
            'INSERT INTO `'._DB_PREFIX_.self::SELECTION_TABLE.'` (`id_cart`,`id_order`,`service`,`locker`) VALUES ('
            .(int) $cartId.','.(int) $orderId.',\''.pSQL($service).'\',\''.pSQL($locker).'\') '
            .'ON DUPLICATE KEY UPDATE `id_order`='.(int) $orderId.', `service`=\''.pSQL($service).'\', `locker`=\''.pSQL($locker).'\''
        );
    }

    private function resolveSelection($persistPosted = false)
    {
        $cartId = isset($this->context->cart) ? (int) $this->context->cart->id : 0;
        $saved = $this->savedSelection($cartId);
        $postedService = $this->postedService();
        $postedLocker = $this->postedLocker();

        $service = $postedService !== '' ? $postedService : $saved['service'];
        $locker = $postedLocker !== '' ? $postedLocker : $saved['locker'];
        if (!$this->requiresLocker($service)) {
            $locker = '';
        }

        if ($persistPosted && $cartId > 0 && ($postedService !== '' || $postedLocker !== '')) {
            $this->saveSelection($cartId, $service, $locker);
        }

        return ['service' => $service, 'locker' => $locker];
    }

    private function defaultServiceKey()
    {
        $available = $this->availableServices();
        $keys = array_keys($available);
        return $keys ? $keys[0] : '';
    }

    public function getOrderShippingCost($params, $shippingCost)
    {
        if (!$this->isOurCarrier((int) $this->id_carrier)) {
            return false;
        }

        $selection = $this->resolveSelection(true);
        $service = $this->validService($selection['service'], true);
        if ($service === '') {
            $service = $this->defaultServiceKey();
        }
        return $service !== '' ? (float) self::SERVICES[$service]['price'] : false;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    public function hookActionCarrierUpdate($params)
    {
        if (!isset($params['id_carrier'], $params['carrier'])) {
            return;
        }
        if ((int) $params['id_carrier'] === (int) Configuration::get(self::CARRIER_CONFIG)) {
            Configuration::updateValue(self::CARRIER_CONFIG, (int) $params['carrier']->id);
        }
    }

    public function hookDisplayCarrierExtraContent($params)
    {
        $idCarrier = isset($params['carrier']) ? $this->hookCarrierId($params['carrier']) : 0;
        if (!$this->isOurCarrier($idCarrier)) {
            return '';
        }

        $available = $this->availableServices();
        $selection = $this->resolveSelection(true);
        $service = $this->validService($selection['service'], true);
        $locker = $selection['locker'];
        $logo = Tools::safeOutput($this->_path.'views/img/courier-guy-logo.jpg');
        $defaultKey = $this->defaultServiceKey();
        $currentKey = $service !== '' ? $service : $defaultKey;
        $currentPrice = $currentKey !== '' ? self::SERVICES[$currentKey]['price'] : 0;
        $isInternational = $this->deliveryCountryIso() !== 'ZA';

        $options = '<option value="">Choose a Courier Guy service</option>';
        foreach ($available as $key => $item) {
            $selected = $service === $key ? ' selected' : '';
            $label = $item['label'].' · '.$item['speed'].' · From R'.number_format($item['price'], 2);
            $options .= '<option value="'.Tools::safeOutput($key).'" data-price="'.number_format($item['price'], 2, '.', '').'" data-delay="'.Tools::safeOutput($item['speed']).'" data-description="'.Tools::safeOutput($item['description']).'" data-locker="'.(!empty($item['requires_locker']) ? '1' : '0').'"'.$selected.'>'.Tools::safeOutput($label).'</option>';
        }

        $css = <<<'CSS'
.cgd-card{--blue:#174b93;--blue-dark:#113c79;--orange:#f58220;--ink:#172334;--muted:#687382;margin:10px 0 6px;border:1px solid #e4e9f0;border-radius:15px;background:#fff;box-shadow:0 9px 28px rgba(21,52,91,.08);overflow:hidden;font-family:inherit}
.cgd-head{display:flex;align-items:center;gap:13px;padding:13px 14px;border-bottom:1px solid #edf0f5;background:linear-gradient(135deg,#fff,#f8fbff)}
.cgd-logo{width:76px;height:54px;flex:0 0 76px;border-radius:9px;object-fit:contain;background:#fff;padding:4px;border:1px solid #edf0f4}
.cgd-copy{min-width:0;flex:1}.cgd-title{margin:0;color:var(--ink);font-size:14.5px;font-weight:850;line-height:1.25}.cgd-note{display:block;margin-top:3px;color:var(--muted);font-size:10.8px;line-height:1.4}
.cgd-price{white-space:nowrap;color:var(--blue);font-size:14px;font-weight:850;text-align:right}.cgd-price small{display:block;color:#8d96a1;font-size:8.8px;font-weight:750;text-transform:uppercase;letter-spacing:.05em}.cgd-from{font-size:10px;color:#7b8590;font-weight:700}
.cgd-body{padding:13px 14px 14px;background:#fbfcfe}.cgd-field{margin-bottom:12px}.cgd-field:last-child{margin-bottom:0}.cgd-label{display:flex;align-items:center;gap:5px;margin:0 0 6px;color:var(--ink);font-size:12.5px;font-weight:800}.cgd-required{color:#d52424;font-size:15px;font-weight:900}.cgd-badge{margin-left:auto;border-radius:999px;background:#eaf2ff;color:var(--blue-dark);padding:3px 7px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.cgd-select,.cgd-locker{width:100%!important;min-height:46px!important;margin:0!important;border:1.5px solid #c9d6e7!important;border-radius:10px!important;background:#fff!important;color:#1e2c3b!important;font-size:13px!important;line-height:1.3!important;box-shadow:none!important;transition:border-color .18s ease,box-shadow .18s ease}.cgd-select{padding:10px 38px 10px 12px!important}.cgd-locker{padding:10px 12px 10px 40px!important}.cgd-select:focus,.cgd-locker:focus{outline:none!important;border-color:var(--blue)!important;box-shadow:0 0 0 3px rgba(23,75,147,.12)!important}.cgd-invalid{border-color:#d52424!important;box-shadow:0 0 0 3px rgba(213,36,36,.08)!important}
.cgd-input-wrap{position:relative}.cgd-pin{position:absolute;left:12px;top:50%;width:17px;height:17px;transform:translateY(-50%);color:var(--orange);pointer-events:none}.cgd-help{margin:6px 0 0;color:#737d89;font-size:10.5px;line-height:1.45}.cgd-error{display:none;margin-top:6px;color:#c91f1f;font-size:10.5px;font-weight:700}.cgd-error.is-visible{display:block}
.cgd-summary{display:none;margin-top:8px;padding:9px 10px;border:1px solid #e6ebf2;border-left:3px solid var(--orange);border-radius:8px;background:#fff;color:#53606f;font-size:10.5px;line-height:1.45}.cgd-summary.is-visible{display:block}.cgd-locker-field{display:none}.cgd-locker-field.is-visible{display:block}.cgd-scope{margin:0 0 10px;padding:7px 9px;border-radius:8px;background:#eef5ff;color:#335474;font-size:10.5px;line-height:1.4}
@media(max-width:767px){.cgd-card{border-radius:12px}.cgd-head{padding:10px 11px;gap:9px}.cgd-logo{width:62px;height:46px;flex-basis:62px}.cgd-title{font-size:13px}.cgd-note{font-size:10px}.cgd-body{padding:11px}.cgd-select,.cgd-locker{font-size:16px!important;min-height:45px!important}.cgd-price{font-size:13px}}
CSS;

        $js = <<<'JS'
(function(){
  function card(){return document.querySelector('.cgd-card[data-cgd-carrier]');}
  function radioFor(c){
    if(!c)return null;
    var extra=c.closest('.carrier-extra-content,.js-carrier-extra-content');
    if(extra){
      var prev=extra.previousElementSibling;
      while(prev){
        if(prev.matches&&prev.matches('.delivery-option')){
          var local=prev.querySelector('input[type=radio][name^="delivery_option"]');
          if(local)return local;
        }
        prev=prev.previousElementSibling;
      }
    }
    var id=String(c.getAttribute('data-cgd-carrier')||'');
    var radios=document.querySelectorAll('input[type=radio][name^="delivery_option"]');
    for(var i=0;i<radios.length;i++){
      if(String(radios[i].value||'').split(',').indexOf(id)!==-1)return radios[i];
    }
    return null;
  }
  function isActive(c){var r=radioFor(c);return !!(r&&r.checked);}
  function service(c){return c?c.querySelector('.cgd-select'):null;}
  function locker(c){return c?c.querySelector('.cgd-locker'):null;}
  function err(c,name){return c?c.querySelector('[data-cgd-error="'+name+'"]'):null;}
  function selectedOption(c){var s=service(c);return s&&s.selectedIndex>=0?s.options[s.selectedIndex]:null;}
  function requiresLocker(c){var o=selectedOption(c);return !!(o&&o.value&&o.getAttribute('data-locker')==='1');}
  function setError(el,errorEl,msg){if(!el)return;el.classList.add('cgd-invalid');el.setAttribute('aria-invalid','true');if(el.setCustomValidity)el.setCustomValidity(msg);if(errorEl){errorEl.textContent=msg;errorEl.classList.add('is-visible');}}
  function clearError(el,errorEl){if(!el)return;el.classList.remove('cgd-invalid');el.removeAttribute('aria-invalid');if(el.setCustomValidity)el.setCustomValidity('');if(errorEl)errorEl.classList.remove('is-visible');}
  function updateVisual(c){
    if(!c)return;
    var s=service(c), o=selectedOption(c), price=c.querySelector('.cgd-current-price'), summary=c.querySelector('.cgd-summary'), lf=c.querySelector('.cgd-locker-field'), st=locker(c);
    if(o&&o.value){
      if(price)price.textContent='R'+o.getAttribute('data-price');
      if(summary){summary.innerHTML='<strong>'+o.textContent.split(' · From R')[0]+'</strong><br>'+o.getAttribute('data-description');summary.classList.add('is-visible');}
    }else{
      if(price)price.textContent=c.getAttribute('data-default-price')||'From R60.00';
      if(summary)summary.classList.remove('is-visible');
    }
    var need=requiresLocker(c);
    if(lf)lf.classList.toggle('is-visible',need);
    if(st){st.required=isActive(c)&&need;st.setAttribute('aria-required',st.required?'true':'false');if(!need)clearError(st,err(c,'locker'));}
  }
  function syncRequired(){
    var c=card();if(!c)return;
    var active=isActive(c), s=service(c), st=locker(c);
    if(s){s.required=active;s.setAttribute('aria-required',active?'true':'false');if(!active)clearError(s,err(c,'service'));}
    if(st){st.required=active&&requiresLocker(c);st.setAttribute('aria-required',st.required?'true':'false');if(!st.required)clearError(st,err(c,'locker'));}
    updateVisual(c);
  }
  function valid(focus){
    var c=card();if(!c||!isActive(c))return true;
    var s=service(c), st=locker(c), ok=true, first=null;
    if(!s||!s.value){setError(s,err(c,'service'),'Please choose a Courier Guy service.');ok=false;first=s;}else clearError(s,err(c,'service'));
    if(requiresLocker(c)&&(!st||st.value.trim().length<3)){
      setError(st,err(c,'locker'),st&&st.value.trim().length?'Please enter at least 3 characters for the PUDO locker.':'Please enter the destination PUDO locker.');ok=false;if(!first)first=st;
    }else if(st){clearError(st,err(c,'locker'));}
    if(!ok&&focus&&first){try{first.focus({preventScroll:true});}catch(e){first.focus();}if(first.scrollIntoView)first.scrollIntoView({behavior:'smooth',block:'center'});}return ok;
  }
  function refreshPrice(c){
    updateVisual(c);
    var r=radioFor(c);
    if(r&&r.checked){
      try{r.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){var ev=document.createEvent('HTMLEvents');ev.initEvent('change',true,false);r.dispatchEvent(ev);}
    }
  }
  function bind(){
    var c=card();if(!c)return;
    var s=service(c), st=locker(c);
    if(s&&!s.dataset.cgdBound){s.dataset.cgdBound='1';s.addEventListener('change',function(){clearError(s,err(c,'service'));refreshPrice(c);});}
    if(st&&!st.dataset.cgdBound){st.dataset.cgdBound='1';st.addEventListener('input',function(){if(this.value.trim().length>=3)clearError(st,err(c,'locker'));});}
    syncRequired();
  }
  if(!window.courierGuyDeliveryBound){
    window.courierGuyDeliveryBound=true;
    document.addEventListener('change',function(e){if(e.target&&e.target.matches('input[type=radio][name^="delivery_option"]'))setTimeout(function(){bind();syncRequired();},20);});
    document.addEventListener('submit',function(e){var f=e.target;if(f&&(f.id==='js-delivery'||f.querySelector&&f.querySelector('input[type=radio][name^="delivery_option"]'))&&!valid(true)){e.preventDefault();e.stopImmediatePropagation();}},true);
    document.addEventListener('click',function(e){var b=e.target&&e.target.closest?e.target.closest('#js-delivery button[type=submit],#js-delivery .continue,button[name=confirmDeliveryOption]'):null;if(b&&!valid(true)){e.preventDefault();e.stopImmediatePropagation();}},true);
    if(window.prestashop&&typeof window.prestashop.on==='function'){
      window.prestashop.on('updatedDeliveryForm',function(){setTimeout(bind,30);});
      window.prestashop.on('opcCarriersUpdated',function(){setTimeout(bind,30);});
    }
    document.addEventListener('DOMContentLoaded',bind);
  }
  bind();
})();
JS;

        $summary = '';
        if ($service !== '') {
            $summary = '<strong>'.Tools::safeOutput(self::SERVICES[$service]['label'].' · From R'.number_format(self::SERVICES[$service]['price'], 2)).'</strong><br>'.Tools::safeOutput(self::SERVICES[$service]['description']);
        }

        $scopeText = $isInternational
            ? 'International destination detected — International Express is available.'
            : 'South African destination detected — choose the Courier Guy service that suits this order.';

        return '<style class="courierguydelivery-styles">'.$css.'</style>'
            .'<div class="cgd-card" data-cgd-carrier="'.(int) $idCarrier.'" data-default-price="From R'.number_format($currentPrice, 2).'">'
            .'<div class="cgd-head">'
            .'<img class="cgd-logo" src="'.$logo.'" alt="The Courier Guy">'
            .'<div class="cgd-copy"><h4 class="cgd-title">The Courier Guy</h4><span class="cgd-note">One courier option. Choose the service tier that fits your delivery.</span></div>'
            .'<div class="cgd-price"><small>Selected rate</small><span class="cgd-current-price">'.($service !== '' ? 'R'.number_format($currentPrice, 2) : 'From R'.number_format($currentPrice, 2)).'</span></div>'
            .'</div>'
            .'<div class="cgd-body">'
            .'<div class="cgd-scope">'.Tools::safeOutput($scopeText).'</div>'
            .'<div class="cgd-field">'
            .'<label class="cgd-label" for="courierguydelivery_service">Courier service <span class="cgd-required">*</span><span class="cgd-badge">Required</span></label>'
            .'<select class="form-control cgd-select" id="courierguydelivery_service" name="courierguydelivery_service" aria-required="false">'.$options.'</select>'
            .'<div class="cgd-summary'.($service !== '' ? ' is-visible' : '').'">'.$summary.'</div>'
            .'<small class="cgd-error" data-cgd-error="service" role="alert">Please choose a Courier Guy service.</small>'
            .'</div>'
            .'<div class="cgd-field cgd-locker-field'.($this->requiresLocker($service) ? ' is-visible' : '').'">'
            .'<label class="cgd-label" for="courierguydelivery_locker">Destination PUDO locker <span class="cgd-required">*</span><span class="cgd-badge">Required</span></label>'
            .'<div class="cgd-input-wrap">'
            .'<svg class="cgd-pin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.05 7-12a7 7 0 1 0-14 0c0 5.95 7 12 7 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9" r="2.4" stroke="currentColor" stroke-width="1.8"/></svg>'
            .'<input class="form-control cgd-locker" id="courierguydelivery_locker" name="courierguydelivery_locker" minlength="3" maxlength="255" value="'.Tools::safeOutput($locker).'" placeholder="e.g. PUDO locker name / location" aria-required="false">'
            .'</div>'
            .'<p class="cgd-help">Required only for Locker-to-Locker. Enter the destination PUDO locker where the customer will collect the parcel.</p>'
            .'<small class="cgd-error" data-cgd-error="locker" role="alert">Please enter the destination PUDO locker.</small>'
            .'</div>'
            .'</div>'
            .'</div>'
            .'<script>'.$js.'</script>';
    }

    public function hookActionValidateStepComplete($params)
    {
        if (($params['step_name'] ?? '') !== 'delivery') {
            return;
        }

        $request = isset($params['request_params']) && is_array($params['request_params'])
            ? $params['request_params']
            : [];

        $rawService = isset($request['courierguydelivery_service'])
            ? (string) $request['courierguydelivery_service']
            : (string) Tools::getValue('courierguydelivery_service', '');
        $rawLocker = isset($request['courierguydelivery_locker'])
            ? (string) $request['courierguydelivery_locker']
            : (string) Tools::getValue('courierguydelivery_locker', '');

        $service = $this->validService($rawService, true);
        $locker = trim($rawLocker);
        $errors = [];

        if ($service === '') {
            $errors[] = $this->l('Please choose a Courier Guy delivery service.');
        }
        if ($service !== '' && $this->requiresLocker($service) && Tools::strlen($locker) < 3) {
            $errors[] = $this->l('Please enter the destination PUDO locker (at least 3 characters).');
        }

        if ($errors) {
            $params['completed'] = false;
            if (isset($this->context->controller)) {
                foreach ($errors as $error) {
                    if (!in_array($error, (array) $this->context->controller->errors, true)) {
                        $this->context->controller->errors[] = $error;
                    }
                }
            }
            return;
        }

        if (isset($this->context->cart) && (int) $this->context->cart->id > 0) {
            $this->saveSelection((int) $this->context->cart->id, $service, $locker);
        }
    }

    public function hookActionValidateOrder($params)
    {
        if (!isset($params['order'], $params['cart'])) {
            return;
        }
        $order = $params['order'];
        $cart = $params['cart'];
        if (!$this->isOurCarrier((int) $order->id_carrier)) {
            return;
        }

        $selection = $this->resolveSelection(true);
        $this->saveSelection((int) $cart->id, $selection['service'], $selection['locker'], (int) $order->id);
    }

    private function orderSelection($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `service`, `locker` FROM `'._DB_PREFIX_.self::SELECTION_TABLE.'` WHERE `id_order`='.(int) $idOrder
        );
        return [
            'service' => $this->validService($row['service'] ?? '', false),
            'locker' => (string) ($row['locker'] ?? ''),
        ];
    }

    public function hookDisplayOrderConfirmation($params)
    {
        $order = $params['order'] ?? null;
        if (!$order || !$this->isOurCarrier((int) $order->id_carrier)) {
            return '';
        }
        $selection = $this->orderSelection((int) $order->id);
        $serviceText = $selection['service'] !== ''
            ? self::SERVICES[$selection['service']]['label'].' — R'.number_format(self::SERVICES[$selection['service']]['price'], 2)
            : 'Service details not captured';

        $html = '<div class="alert alert-info"><strong>The Courier Guy</strong><br><strong>Service:</strong> '.Tools::safeOutput($serviceText);
        if ($this->requiresLocker($selection['service'])) {
            $html .= '<br><strong>Destination PUDO locker:</strong> '.Tools::safeOutput($selection['locker']);
        }
        return $html.'</div>';
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        if (!$idOrder) {
            return '';
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || !$this->isOurCarrier((int) $order->id_carrier)) {
            return '';
        }

        $selection = $this->orderSelection($idOrder);
        $serviceText = '<span class="text-danger">Not captured</span>';
        if ($selection['service'] !== '') {
            $item = self::SERVICES[$selection['service']];
            $serviceText = Tools::safeOutput($item['label']).' (R'.number_format($item['price'], 2).')';
        }

        $html = '<div class="card mt-2"><div class="card-header"><strong>The Courier Guy</strong></div><div class="card-body"><strong>Service:</strong> '.$serviceText;
        if ($this->requiresLocker($selection['service'])) {
            $lockerText = $selection['locker'] !== '' ? Tools::safeOutput($selection['locker']) : '<span class="text-danger">Not captured</span>';
            $html .= '<br><strong>Destination PUDO locker:</strong> '.$lockerText;
        }
        return $html.'</div></div>';
    }
}
