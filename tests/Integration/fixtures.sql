-- DinkyTags test fixtures. Idempotent: safe to run repeatedly.
-- Loaded by setup.sh after Joomla install + plugin discovery.
SET @now = NOW();
SET NAMES utf8mb4;

-- Enable + configure the plugin (debug on, de_DE, branded default image).
UPDATE jos_extensions
SET enabled = 1,
    params = '{"enabled_views":["article","category","featured","home","other"],"default_image":"images/og-default.png","home_image":"","description_source":"metadesc-first","description_length":"200","image_custom_field":"","emit_article_meta":"1","emit_twitter":"1","twitter_site":"@dinkytest","twitter_creator":"","fb_app_id":"","og_locale":"de_DE","override_mode":"only-if-missing","fallback_to_logo":"0","strip_query_params":"utm_source,utm_medium,utm_campaign,utm_term,utm_content,fbclid,gclid,mc_cid,mc_eid","excluded_components":"com_icagenda","excluded_menu_items":"","debug":"1"}'
WHERE element = 'dinkytags' AND folder = 'system';

-- Article fixtures, one per image-resolution scenario (spec section 7).
INSERT IGNORE INTO jos_content
(id, asset_id, title, alias, introtext, `fulltext`, state, catid, created, created_by, created_by_alias, modified, modified_by, publish_up, images, urls, attribs, version, ordering, metakey, metadesc, access, hits, metadata, featured, language, note)
VALUES
(1, 0, 'FX Fulltext Image', 'fx-fulltext',
 '<p>Einleitungstext fuer den Fulltext-Bild-Fall. Genug Text damit ein Excerpt entstehen koennte wenn keine Meta-Beschreibung vorhanden waere.</p>',
 '<p>Voller Text.</p>', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', @now, 0, @now,
 '{"image_intro":"images/fx-intro.jpg","image_intro_alt":"Intro alt","float_intro":"","image_fulltext":"images/fx-full.jpg","image_fulltext_alt":"Full alt","float_fulltext":""}',
 '{}', '{}', 1, 1, '', 'Preis&amp;nbsp;inklusive. Jetzt lesen im Test.', 1, 0, '{}', 1, '*', ''),

(2, 0, 'FX Intro Only', 'fx-introonly',
 '<p>Einleitungstext fuer den Intro-Bild-Fall.</p>', '', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', '2020-01-01 00:00:00', 0, @now,
 '{"image_intro":"images/fx-intro.jpg","image_intro_alt":"","image_fulltext":"","image_fulltext_alt":""}',
 '{}', '{}', 1, 2, '', 'Normale Meta-Beschreibung fuer FX Intro Only.', 1, 0, '{}', 0, '*', ''),

(3, 0, 'FX Inline Image', 'fx-inline',
 '<p>Lorem ipsum dolor sit amet <img src="images/fx-inline.jpg" alt="inline"> consectetur adipiscing elit sed do eiusmod tempor.</p>',
 '', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', '2020-01-01 00:00:00', 0, @now,
 '{}', '{}', '{}', 1, 3, '', '', 1, 0, '{}', 0, '*', ''),

(4, 0, 'FX No Image', 'fx-noimage',
 '<p>Dieser Beitrag hat kein eigenes Bild und keine Meta-Beschreibung. Der Einleitungstext ist lang genug, dass daraus eine Beschreibung mit Wortgrenzen-Schnitt und Auslassungszeichen entstehen sollte, wenn die Laenge von zweihundert Zeichen ueberschritten wird, was hier absichtlich der Fall ist damit der Schnitt getestet werden kann.</p>',
 '', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', '2020-01-01 00:00:00', 0, @now,
 '{}', '{}', '{}', 1, 4, '', '', 1, 0, '{}', 0, '*', ''),

(5, 0, 'FX Remote Image', 'fx-remote',
 '<p>Einleitungstext fuer den Remote-Bild-Fall.</p>', '', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', '2020-01-01 00:00:00', 0, @now,
 '{"image_intro":"","image_fulltext":"https://example.com/remote-og.png","image_fulltext_alt":"Remote alt"}',
 '{}', '{}', 1, 5, '', 'Meta-Beschreibung fuer den Remote-Bild-Fall.', 1, 0, '{}', 0, '*', ''),

(6, 0, 'FX Small Image', 'fx-smallimg',
 '<p>Einleitungstext fuer den Kleinbild-Fall.</p>', '', 1, 2, @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), '', '2020-01-01 00:00:00', 0, @now,
 '{"image_fulltext":"images/fx-small.png","image_fulltext_alt":""}',
 '{}', '{}', 1, 6, '', 'Meta-Beschreibung fuer den Kleinbild-Fall.', 1, 0, '{}', 0, '*', '');

-- One tag, mapped to article 1 (tests repeated article:tag output).
INSERT IGNORE INTO jos_tags
(id, parent_id, lft, rgt, level, path, title, alias, published, access, `language`, params, metadesc, metadata, images, urls, description, created_time, created_user_id, modified_time, note)
VALUES
(2, 1, 1, 2, 1, 'test-tag', 'Test Tag', 'test-tag', 1, 1, '*', '{}', '', '{}', '{}', '{}', '', @now, (SELECT id FROM jos_users WHERE username='admin' LIMIT 1), @now, '');

INSERT IGNORE INTO jos_contentitem_tag_map
(type_alias, core_content_id, content_item_id, tag_id, tag_date, type_id)
VALUES
('com_content.article', 1, 1, 2, @now, 1);
