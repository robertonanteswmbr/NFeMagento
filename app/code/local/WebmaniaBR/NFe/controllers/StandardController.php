<?php

class WebmaniaBR_Nfe_StandardController extends Mage_Adminhtml_Controller_Action
{

  public function get_referer_url() {

    $refererUrl = $this->getRequest()->getServer('HTTP_REFERER');

    if ($url = $this->getRequest()->getParam(self::PARAM_NAME_REFERER_URL)) {
      $refererUrl = $url;
    }
    if ($url = $this->getRequest()->getParam(self::PARAM_NAME_BASE64_URL)) {
      $refererUrl = Mage::helper('core')->urlDecode($url);
    }
    if ($url = $this->getRequest()->getParam(self::PARAM_NAME_URL_ENCODED)) {
      $refererUrl = Mage::helper('core')->urlDecode($url);
    }

    if (!$this->_isUrlInternal($refererUrl)) {
      $refererUrl = Mage::app()->getStore()->getBaseUrl();
    }

    return $refererUrl;

  }

  public function updateAction() {

    $chave_acesso = $this->getRequest()->getParam('chave_acesso');
    $order_id = $this->getRequest()->getParam('order_id');

    $notafiscal = new WebmaniaBR_NFe_Model_Observer;
    $response = $notafiscal->updateNotaFiscal($chave_acesso);
    if (isset($response->error)){

      Mage::getSingleton('core/session')->addError("Erro ao atualizar nota: ".$response->error);

    }else{

      $new_status = $response->status;
      $order = Mage::getModel('sales/order')->load($order_id);

      $nfe_data = unserialize(base64_decode($order->getData('all_nfe')));

      foreach($nfe_data as &$order_nfe){
        if($order_nfe['chave_acesso'] == $chave_acesso){
          $order_nfe['status'] = $new_status;
        }
      }

      $nfe_data_str = base64_encode(serialize($nfe_data));
      $order->setData('all_nfe', $nfe_data_str);
      $order->save();
      Mage::getSingleton('core/session')->addSuccess("Nota Fiscal atualizada com sucesso.");

    }


    $referer_url = $this->get_referer_url();
    $this->_redirectUrl($referer_url);
  }

  public function emitirAction(){


    $orders = $_POST['order_ids'];

    foreach ($orders as $number){

      $order = Mage::getModel('sales/order')->load($number);

      // Emissão automática de Nota Fiscal
      $notafiscal = new WebmaniaBR_NFe_Model_Observer;
      $response = $notafiscal->emitirNfe( $order, null, null, true );
      $orderno = (int) $order->getIncrementId();
      if (isset($response->error)) {
        Mage::getSingleton('core/session')->addError("Nota Fiscal #".$orderno.': '.$response->error);
        if ($response->log){
          foreach ($response->log as $erros){
            foreach ($erros as $erro) {
              Mage::getSingleton('core/session')->addError("- ".$erro);
            }
          }
        }
      } else {

        $setup = new Mage_Sales_Model_Resource_Setup('core_setup');

        $attribute  = array(
          'type' => 'text',
          'input' => 'text',
          'label' => 'NFe emitidas',
          'global' => 0,
          'visible' => 1,
          'required' => 0,
          'user_defined' => 1,
          'visible_on_front' => 0,
        );

        $setup->addAttribute('order', 'all_nfe', $attribute);
        $setup->endSetup();

        $existing_nfe = unserialize(base64_decode($order->getData('all_nfe')));
        if(!$existing_nfe) $existing_nfe = array();

        $nfe_info = array(
          'status'       => (string) $response->status,
          'chave_acesso' => $response->chave,
          'n_recibo'     => (int) $response->recibo,
          'n_nfe'        => (int) $response->nfe,
          'n_serie'      => (int) $response->serie,
          'url_xml'      => (string) $response->xml,
          'url_danfe'    => (string) $response->danfe,
          'data'         => date('d/m/Y'),
        );

        $existing_nfe[] = $nfe_info;

        $nfe_info_str = base64_encode(serialize($existing_nfe));
        $order->setData('all_nfe', $nfe_info_str);
        $order->save();
        Mage::getSingleton('core/session')->addSuccess("Nota Fiscal #".$orderno.': Emitida com sucesso.');
      }

    }

    session_write_close();
    $url = Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/');
    $this->_redirectUrl($url);

  }

}
