<?php

namespace Drupal\mass_content\Plugin\Field\FieldFormatter;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Drupal\mass_content\Field\FieldType\DynamicLinkItem;

/**
 * Plugin implementation of the 'dynamic_link_separate' formatter.
 *
 * @FieldFormatter(
 *   id = "dynamic_link_separate",
 *   label = @Translation("Separate link text (with computed title) and URL"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class DynamicLinkSeparateFormatter extends LinkFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      // Link title may be returned as render array, so trim cannot be used.
      'trim_length' => '',
      'rel' => '',
      'target' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    // Trimming is not usable for this formatter.
    unset($elements['trim_length']);
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    foreach ($items as $delta => $item) {
      if (!$item instanceof DynamicLinkItem) {
        throw new \RuntimeException('DynamicLinkSeparateFormatter can only be used to format DynamicLinkItem fields.');
      }
      $url = $this->buildUrl($item);

      // Uses computed title property from DynamicLinkItem.
      $link_title = $item->computed_title;
      $url_title = $url->toString();

      if (empty($link_title)) {
        $link_title = $url_title;
      }

      $extra = [];
      if (!$url->isExternal()) {
        $extra['date'] = $item->computed_date;
      }

      $extra['type'] = $item->computed_type;

      $element[$delta] = [
        '#theme' => 'link_formatter_link_separate',
        '#title' => $link_title,
        '#url_title' => $url_title,
        '#url' => $url,
        '#extra' => $extra,
      ];

      // Add #access and cacheability metadata to remove links to deleted and unpublished content.
      if ($url->isRouted() && $url->getRouteName() == 'entity.node.canonical') {
        if ($entity = \Drupal::entityTypeManager()->getStorage(key($url->getRouteParameters()))->load($url->getRouteParameters()['node'])) {
          $access = $entity->access('view', NULL, TRUE);
        }
        else {
          $access = AccessResult::forbidden('Cannot load entity');
        }
        $metadata = CacheableMetadata::createFromObject($access);
        $metadata->applyTo($element[$delta]);
        $element[$delta]['#access'] = $access->isAllowed();
      }

      if (!empty($item->_attributes)) {
        // Set our RDFa attributes on the <a> element that is being built.
        $url->setOption('attributes', $item->_attributes);

        // Unset field item attributes since they have been included in the
        // formatter output and should not be rendered in the field template.
        unset($item->_attributes);
      }
    }
    return $element;
  }

}
