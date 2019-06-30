<?php

namespace Drupal\commerce_reuse_profile\Plugin\Commerce\CheckoutPane;

use Drupal\commerce\InlineFormManager;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @CommerceCheckoutPane(
 *  id = "reuse_billing_information_pane",
 *  label = @Translation("Reuse billing information"),
 *  display_label = @Translation("Billing information"),
 *  wrapper_element = "fieldset",
 * )
 */
class ReuseBillingInformationPane extends CheckoutPaneBase implements CheckoutPaneInterface{

 /**
   * The inline form manager.
   *
   * @var \Drupal\commerce\InlineFormManager
   */
  protected $inlineFormManager;

  /**
   * Constructs a new BillingInformation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce\InlineFormManager $inline_form_manager
   *   The inline form manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow, EntityTypeManagerInterface $entity_type_manager, InlineFormManager $inline_form_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);

    $this->inlineFormManager = $inline_form_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_inline_form')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    $summary = [];
    if ($profile = $this->order->getBillingProfile()) {
      $profile_view_builder = $this->entityTypeManager->getViewBuilder('profile');
      $summary = $profile_view_builder->view($profile, 'default');
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $cid = $this->order->getCustomerId();
    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $user = $this->entityTypeManager->getStorage('user')->load($cid);
    $profiles = empty($cid) ? NULL : $profile_storage->loadMultipleByUser($user, 'customer');
    // If guest or no profiles show profile form
    if ( $profiles ) {
      foreach ($profiles as $key => $value) {
        if ($value->get('address')) {
          $address = $value->get('address')->getValue();
          $addressFinal = t('%firstname %lastname, %address, %locality, %country', [
            '%firstname' => $address[0]['given_name'],
            '%lastname' => $address[0]['family_name'],
            '%address' => $address[0]['address_line1'] . ' ' . $address[0]['address_line2'],
            '%locality' => $address[0]['locality'] . ' ' . $address[0]['postal_code'] . ', ' . $address[0]['administrative_area'],
            '%country' => $address[0]['country_code'],
          ]);
          $profilesData[$key] = $addressFinal;
          $defaultValue = $key;
        }
      }
      // TODO: Check loadDefaultByUser option or check of previously set on edit form
      $profilesData[0] = $this->t('New address');
      $pane_form['address_list'] = [
        '#type' => 'radios',
//        '#title' => $this->t('Saved Addresses'),
        '#id' => 'address_list',
        '#options' => $profilesData,
        '#weight' => -100,
        '#default_value' => $defaultValue,
        '#suffix' => '<div id="modal_ajax_form"></div>',
        '#ajax' => [
          'callback' => [$this, 'addressCheckoutCallback'],
          'event' => 'change',
          'effect' => 'fade',
          'wrapper' => 'modal_ajax_form',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Processing'),
          ],
        ],
      ];
    }
    else {
      $profile = $this->order->getBillingProfile();
      if (!$profile) {
        $profile = $profile_storage->create([
          'type' => 'customer',
          'uid' => $this->order->getCustomerId(),
        ]);
      }
      $inline_form = $this->inlineFormManager->createInstance('customer_profile', [
        'available_countries' => $this->order->getStore()->getBillingCountries(),
      ], $profile);

      $pane_form['profile'] = [
        '#parents' => array_merge($pane_form['#parents'], ['profile']),
        '#inline_form' => $inline_form,
      ];
      $pane_form['profile'] = $inline_form->buildInlineForm($pane_form['profile'], $form_state);
    }
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);
    $id = $values['address_list'];
    if (empty($id)) {
      /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
      $inline_form = $pane_form['profile']['#inline_form'];
      /** @var \Drupal\profile\Entity\ProfileInterface $profile */
      $profile = $inline_form->getEntity();
    }
    else {
      $profile_storage = $this->entityTypeManager->getStorage('profile');
      $profile = $profile_storage->load($id);
    }
    $this->order->setBillingProfile($profile);
  }

  /**
   * Ajax callback function.
   *
   * @param array $form
   *   Form array.
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   Form State.
   *
   * @return Drupal\Core\Ajax\AjaxResponse
   *   Ajax response
   */
  public function addressCheckoutCallback(array &$pane_form, FormStateInterface &$form_state) {
    $values = $form_state->getValue($pane_form['#parents']);
    $id = $form_state->getValue('address_list');
    if (empty($id)) {
      $profile = $this->order->getBillingProfile();
      if (!$profile) {
        $profile_storage = $this->entityTypeManager->getStorage('profile');
        $profile = $profile_storage->create([
          'type' => 'customer',
          'uid' => $this->order->getCustomerId(),
        ]);
      }
      $inline_form = $this->inlineFormManager->createInstance('customer_profile', [
        'available_countries' => $this->order->getStore()->getBillingCountries(),
      ], $profile);

      $pane_form['profile'] = [
        '#parents' => array_merge($pane_form['#parents'], ['profile']),
        '#inline_form' => $inline_form,
      ];
      $pane_form['profile'] = $inline_form->buildInlineForm($pane_form['profile'], $form_state);
      $pane_form['profile']['#prefix'] = '<div id="modal_ajax_form">';
      $pane_form['profile']['#suffix'] = '</div>';
      return $pane_form['profile'];
    }
    else { 
      $markup = 'Blanco';
      return ['#markup' => $markup];
    }
  }

}
