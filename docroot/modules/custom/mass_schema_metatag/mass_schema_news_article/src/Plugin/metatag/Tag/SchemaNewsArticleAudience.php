<?php

namespace Drupal\mass_schema_news_article\Plugin\metatag\Tag;

use Drupal\schema_metatag\Plugin\metatag\Tag\SchemaNameBase;

/**
 * Provides a plugin for the 'schema_news_article_audience' meta tag.
 *
 * @MetatagTag(
 *   id = "schema_news_article_audience",
 *   label = @Translation("audience"),
 *   description = @Translation("An intended audience, i.e. a group for whom something was created."),
 *   name = "audience",
 *   group = "schema_news_article",
 *   weight = 1,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class SchemaNewsArticleAudience extends SchemaNameBase {

  /**
   * Generate a form element for this meta tag.
   */
  public function form(array $element = []) {
    $form = parent::form($element);
    $form['#attributes']['placeholder'] = '[node:summary]';
    return $form;
  }

}
