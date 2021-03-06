<?php

/**
 * @file
 * Contains \Drupal\page_manager\Plugin\DisplayVariant\BlockDisplayVariant.
 */

namespace Drupal\page_manager\Plugin\DisplayVariant;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\Element;
use Drupal\ctools\Plugin\DisplayVariant\BlockDisplayVariant;

/**
 * Provides a variant plugin that simply contains blocks.
 *
 * @DisplayVariant(
 *   id = "block_display",
 *   admin_label = @Translation("Block page")
 * )
 */
class PageBlockDisplayVariant extends BlockDisplayVariant {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Set default page cache keys that include the display.
    $build['#cache']['keys'] = [
      'page_manager_block_display',
      $this->id(),
    ];
    $build['#pre_render'][] = [$this, 'buildRegions'];
    return $build;
  }

  /**
   * #pre_render callback for building the regions.
   */
  public function buildRegions(array $build) {
    $cacheability = CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($this);

    $contexts = $this->getContexts();
    foreach ($this->getRegionAssignments() as $region => $blocks) {
      if (!$blocks) {
        continue;
      }

      $region_name = Html::getClass("block-region-$region");
      $build[$region]['#prefix'] = '<div class="' . $region_name . '">';
      $build[$region]['#suffix'] = '</div>';

      /** @var \Drupal\Core\Block\BlockPluginInterface[] $blocks */
      $weight = 0;
      foreach ($blocks as $block_id => $block) {
        if ($block instanceof ContextAwarePluginInterface) {
          $this->contextHandler()->applyContextMapping($block, $contexts);
        }
        $access = $block->access($this->account, TRUE);
        $cacheability->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          continue;
        }

        $block_build = [
          '#theme' => 'block',
          '#attributes' => [],
          '#weight' => $weight++,
          '#configuration' => $block->getConfiguration(),
          '#plugin_id' => $block->getPluginId(),
          '#base_plugin_id' => $block->getBaseId(),
          '#derivative_plugin_id' => $block->getDerivativeId(),
          '#block_plugin' => $block,
          '#pre_render' => [[$this, 'buildBlock']],
          '#cache' => [
            'keys' => ['page_manager_block_display', $this->id(), 'block', $block_id],
            // Each block needs cache tags of the page and the block plugin, as
            // only the page is a config entity that will trigger cache tag
            // invalidations in case of block configuration changes.
            'tags' => Cache::mergeTags($this->getCacheTags(), $block->getCacheTags()),
            'contexts' => $block->getCacheContexts(),
            'max-age' => $block->getCacheMaxAge(),
          ],
        ];

        // Temporary fix for content blocks.
        $original_block_build = $block->build();
        if (isset($original_block_build['#contextual_links'])) {
          $block_build['#contextual_links'] = $original_block_build['#contextual_links'];
        }


        // Merge the cacheability metadata of blocks into the page. This helps
        // to avoid cache redirects if the blocks have more cache contexts than
        // the page, which the page must respect as well.
        $cacheability->addCacheableDependency($block);

        $build[$region][$block_id] = $block_build;
      }
    }

    $build['#title'] = $this->renderPageTitle($this->configuration['page_title']);

    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * #pre_render callback for building a block.
   *
   * Renders the content using the provided block plugin, if there is no
   * content, aborts rendering, and makes sure the block won't be rendered.
   */
  public function buildBlock($build) {
    $content = $build['#block_plugin']->build();
    // Remove the block plugin from the render array.
    unset($build['#block_plugin']);
    if ($content !== NULL && !Element::isEmpty($content)) {
      $build['content'] = $content;
    }
    else {
      // Abort rendering: render as the empty string and ensure this block is
      // render cached, so we can avoid the work of having to repeatedly
      // determine whether the block is empty. E.g. modifying or adding entities
      // could cause the block to no longer be empty.
      $build = [
        '#markup' => '',
        '#cache' => $build['#cache'],
      ];
    }
    // If $content is not empty, then it contains cacheability metadata, and
    // we must merge it with the existing cacheability metadata. This allows
    // blocks to be empty, yet still bubble cacheability metadata, to indicate
    // why they are empty.
    if (!empty($content)) {
      CacheableMetadata::createFromRenderArray($build)
        ->merge(CacheableMetadata::createFromRenderArray($content))
        ->applyTo($build);
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Don't call VariantBase::buildConfigurationForm() on purpose, because it
    // adds a 'Label' field that we don't actually want to use - we store the
    // label on the page variant entity.
    //$form = parent::buildConfigurationForm($form, $form_state);

    // Allow to configure the page title, even when adding a new display.
    // Default to the page label in that case.
    $form['page_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page title'),
      '#description' => $this->t('Configure the page title that will be used for this display.'),
      '#default_value' => $this->configuration['page_title'] ?: '',
    ];

    $form['uuid'] = [
      '#type' => 'value',
      '#value' => $this->configuration['uuid'] ? : $this->uuidGenerator->generate(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if ($form_state->hasValue('page_title')) {
      $this->configuration['page_title'] = $form_state->getValue('page_title');
    }
    if ($form_state->hasValue('uuid')) {
      $this->configuration['uuid'] = $form_state->getValue('uuid');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'page_title' => '',
    ];
  }

  /**
   * Renders the page title and replaces tokens.
   *
   * @param string $page_title
   *   The page title that should be rendered.
   *
   * @return string
   *   The page title after replacing any tokens.
   */
  protected function renderPageTitle($page_title) {
    $data = $this->getContextAsTokenData();
    return $this->token->replace($page_title, $data);
  }

  /**
   * Returns available context as token data.
   *
   * @return array
   *   An array with token data values keyed by token type.
   */
  protected function getContextAsTokenData() {
    $data = [];
    foreach ($this->getContexts() as $context) {
      // @todo Simplify this when token and typed data types are unified in
      //   https://drupal.org/node/2163027.
      if (strpos($context->getContextDefinition()->getDataType(), 'entity:') === 0) {
        $token_type = substr($context->getContextDefinition()->getDataType(), 7);
        if ($token_type == 'taxonomy_term') {
          $token_type = 'term';
        }
        $data[$token_type] = $context->getContextValue();
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionNames() {
    return [
      'top' => 'Top',
      'bottom' => 'Bottom',
    ];
  }

}
