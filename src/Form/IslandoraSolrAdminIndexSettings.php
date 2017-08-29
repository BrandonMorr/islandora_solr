<?php

/**
 * @file
 * Contains \Drupal\islandora_solr\Form\IslandoraSolrAdminIndexSettings.
 */

namespace Drupal\islandora_solr\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

class IslandoraSolrAdminIndexSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_solr_admin_index_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['islandora_solr.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check for the PHP Solr lib class.
    if (!class_exists('Drupal\islandora_solr\SolrPhpClient\Apache\Solr\Apache_Solr_Service')) {
      drupal_set_message(t('This module requires the <a href="@url">Apache Solr PHP Client</a>. Please install the client in the root directory of this module before continuing.', [
        '@url' => 'http://code.google.com/p/solr-php-client',
      ]), 'error');
      return array();
    }
    // Add admin form CSS.
    $form['#attached']['library'][] = 'islandora_solr/islandora-solr-admin';

    $solr_url = $form_state->getValue(['islandora_solr_url']) ? $form_state->getValue(['islandora_solr_url']) : \Drupal::config('islandora_solr.settings')->get('islandora_solr_url');
    // Solr connect triggering handler is dismax or not set on page load.
    // Get request handler.
    $handler = $form_state->getValue(['islandora_solr_request_handler']) ? $form_state->getValue(['islandora_solr_request_handler']) : \Drupal::config('islandora_solr.settings')->get('islandora_solr_request_handler');

    if (strpos($solr_url, 'https://') !== FALSE && strpos($solr_url, 'https://') == 0) {
      $confirmation_message = $this->t('Islandora does not support SSL connections to Solr.');
      $status_image = '/core/misc/icons/e32700/error.svg';
      $solr_avail = FALSE;
    }
    else {
      // Check if Solr is available.
      $solr_avail = islandora_solr_ping($solr_url);
      $full_url = Url::fromUri(islandora_solr_check_http($solr_url));
      $full_url->setOptions(array(
        'attributes' => array(
          'target' => '_blank',
        ),
      ));
      // If solr is available, get the request handlers.
      if ($solr_avail) {
        // Find request handlers (~500ms).
        $handlers = $this->getHandlers($solr_url);

        // Get confirmation message.
        $confirmation_message = t('Successfully connected to Solr server at @link. <sub>(@ms ms)</sub>', array(
          '@link' => Link::fromTextAndUrl($solr_url, $full_url)->toString(),
          '@ms' => number_format($solr_avail, 2),
        ));
        $status_image = '/core/misc/icons/73b355/check.svg';
      }
      else {
        $confirmation_message = t('Unable to connect to Solr server at @link.', array(
          '@link' => Link::fromTextAndUrl($solr_url, $full_url)->toString(),
        ));
        $status_image = '/core/misc/icons/e32700/error.svg';
      }
    }

    // AJAX wrapper for URL checking.
    $form['solr_ajax_wrapper'] = [
      '#prefix' => '<div id="solr-url">',
      '#suffix' => '</div>',
      'solr_available' => [
        '#type' => 'value',
        '#value' => $solr_avail ? TRUE : FALSE,
      ],
    ];
    // Solr URL.
    $form['solr_ajax_wrapper']['islandora_solr_url'] = [
      '#type' => 'textfield',
      '#title' => t('Solr URL'),
      '#size' => 80,
      '#weight' => -1,
      '#description' => t('The URL of the Solr installation. Defaults to localhost:8080/solr.'),
      '#default_value' => $solr_url,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateUrlDiv',
        'event' => 'blur',
        'disable-refocus' => TRUE,
        'wrapper' => 'solr-url',
      ],
    ];

    // Confirmation message.
    $form['solr_ajax_wrapper']['image'] = array(
      '#theme' => 'image',
      '#uri' => $status_image,
    );
    $form['solr_ajax_wrapper']['message'] = [
      '#markup' => $confirmation_message,
    ];

    // Don't show form item if no request handlers are found.
    if (!empty($handlers)) {
      $form['solr_ajax_wrapper']['islandora_solr_request_handler'] = [
        '#type' => 'select',
        '#title' => t('Request handler'),
        '#options' => $handlers,
        '#description' => $this->t('Request handlers, as defined by <a href="@url">solrconfig.xml</a>.', [
          '@url' => 'http://wiki.apache.org/solr/SolrConfigXml',
        ]),
        '#default_value' => $handler,
      ];
    }

    // Solr force delete from index during object purge.
    $form['islandora_solr_force_update_index_after_object_purge'] = [
      '#type' => 'checkbox',
      '#title' => t('Force update of Solr index after an object is deleted'),
      '#weight' => 5,
      '#description' => t('If checked, deleting objects will also force their removal from the Solr index. <br/><strong>Note:</strong> When active, UI consistency will be increased on any pages using Solr queries for display. This setting is not appropriate for every installation (e.g., on sites with a large volume of Solr commits that hit execution limits, or where the Solr index is not directly writable from Drupal).'),
      '#default_value' => \Drupal::config('islandora_solr.settings')->get('islandora_solr_force_update_index_after_object_purge'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('solr_available') == FALSE) {
      return $form_state->setError($form['solr_ajax_wrapper']['islandora_solr_url'], $this->t('Please enter a valid Solr URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('islandora_solr.settings')
      ->set('islandora_solr_url', $form_state->getValue('islandora_solr_url'))
      ->set('islandora_solr_request_handler', $form_state->getValue('islandora_solr_request_handler'))
      ->set('islandora_solr_force_update_index_after_object_purge', $form_state->getValue('islandora_solr_force_update_index_after_object_purge'))
      ->save();
    // Force renewal of the cached value, as the request handler might have
    // changed.
    islandora_solr_check_dismax(TRUE);
    parent::submitForm($form, $form_state);
  }

  /**
   * Get available handlers.
   *
   * @param string $solr_url
   *   URL which points to Solr.
   *
   * @return array
   *   An associative array mapping the names of all request handlers found in
   *   the solrconfig.xml of the Solr instance to themselves.
   */
  public function getHandlers($solr_url) {
    module_load_include('inc', 'islandora_solr', 'includes/utilities');
    $xml = islandora_solr_get_solrconfig_xml($solr_url);
    $handlers = array(
      FALSE => t('Let Solr decide'),
    );
    if ($xml) {
      $xpath = '//requestHandler[@class="solr.SearchHandler" and not(starts-with(@name, "/")) and not(@name="dismax") and not(@name="partitioned")]';
      foreach ($xml->xpath($xpath) as $handler) {
        $handler_name = (string) $handler['name'];
        $handlers[$handler_name] = $handler_name;
      }
    }
    else {
      drupal_set_message(t('Error retrieving @file from Solr.', array('@file' => 'solrconfig.xml')), 'warning');
    }
    return $handlers;
  }

  /**
   * Updates the URL wrapper for the admin form.
   *
   * @param array $form
   *   The Drupal form being configured.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Object containing the state of the form.
   *
   * @return array
   *   Renderable portion of the form to be updated.
   */
  public function updateUrlDiv(array $form, FormStateInterface $form_state) {
    return $form['solr_ajax_wrapper'];
  }

}
