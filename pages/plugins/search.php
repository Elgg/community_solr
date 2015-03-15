<?php

namespace community\solr;

/**
 * Advanced search page, handling plugin filtering and sorting
 */

global $CONFIG;

// Get search-specific settings
$serialized_settings = elgg_get_plugin_setting('search-settings', 'community_plugins');
$settings = unserialize($serialized_settings);
if (!is_array($settings)) {
	$settings = array();
}

$offset = get_input('offset', 0);
$limit = get_input('limit', 10);

// Filters are passed as an associative, multidimensional array with shortened keys (to fit into IE's max URI length)
$filters = get_input('f');

// Default sort is time_created, descending order
$sort = get_input('sort', 'created');
$direction = get_input('direction', 'desc');

$options = array(
	'type'                      => 'object',
	'subtype'                   => 'plugin_project',
    'offset'                    => $offset,
    'limit'                     => $limit,
    'metadata_name_value_pairs' => array(),
    'metadata_case_sensitive'   => false,
	'joins'                     => array(),
);
$wheres = array();
$group_bys = array();

$solr_params = array(
	'fq' => array(), 
	'query' => '',
	'offset' => $offset,
	'limit' => $limit
);

// Handle entity filtering
if (is_array($filters) && !empty($filters)) {
    foreach ($filters as $key => $value) {
        $key = sanitise_string($key);
        switch ($key) {
            case 't' :
            	if (is_array($settings['text']) && in_array('enabled', $settings['text'])) {
                	// Any text value; will be matched against plugin title, description, summary, tags, author name and username
                	if (strlen($value) > 0) {
	                	$solr_params['query'] = $value;
                	}
                }
                break;
	    	// Categories
            case 'c' :
            	if (is_array($settings['category']) && in_array('enabled', $settings['category'])) {
                	if (is_array($value) && !empty($value)) {
						$list = '';
						foreach ($value as $v) {
							if ($list) { $list .= ','; }
							$list .= '"' . $v . '"';
						}
						
						$solr_params['fq']['cat'] = 'plugincat_s:(' . $list . ')';
                	}
            	}
                break;
            case 'l' :
            	if (is_array($settings['licence']) && in_array('enabled', $settings['licence'])) {
                	// Licences
                	if (is_array($value) && !empty($value)) {
                		$list = '';
						foreach ($value as $v) {
							if ($list) { $list .= ','; }
							$list .= '"' . $v . '"';
						}
						
						$solr_params['fq']['license'] = 'license_s:(' . $list . ')';
                	}
            	}
                break;
            case 'v' :
            	if (is_array($settings['version']) && in_array('enabled', $settings['version'])) {
                	// Elgg versions
                	if (is_array($value) && !empty($value)) {
                		$list = '';
						foreach ($value as $v) {
							if ($list) { $list .= ','; }
							$list .= '"' . $v . '"';
						}
						
						$solr_params['fq']['version'] = 'version_ss:(' . $list . ')';
                	}
            	}
            	break;
            case 's' :
            	if (isset($settings['screenshot']) && $settings['screenshot'] == 'enabled') {
                	// Only with screenshot
                	$solr_params['fq']['screenshot'] = 'screenshots_i:1';
            	}
            	break;
        }
    }
}    

// Support for ?owner={username} query parameter
$owner = get_user_by_username(get_input('owner'));
if ($owner) {
	$solr_params['fq']['owner'] = 'owner_guid:' . $owner->guid;
}


// Get objects
elgg_set_context('search');
$result = plugin_search(null, null, array(), $solr_params);
$list = elgg_view_entity_list($result['entities'], array('limit' => $limit, 'offset' => $offset, 'count' => $result['count']));
elgg_set_context('plugins');

$title = elgg_echo('plugins:search:title');

// Add sidebar filter
$sidebar = elgg_view('plugins/filters', array(
	'categories' => $CONFIG->plugincats,
	'versions' => $CONFIG->elgg_versions,
	'licences' => $CONFIG->gpllicenses,
	'current_values' => $filters,
	'settings' => $settings,
));

// Add info block on search results to the main area
if ($result['count']) {
	$first_index = $offset + 1;
	$last_index = min(array($offset + $limit, $result['count']));
	$heading = elgg_view_title(sprintf(elgg_echo('plugins:search:results'), $result['count'], $first_index, $last_index));
} else {
	$heading = elgg_view_title(elgg_echo('plugins:search:noresults'));
	$main = elgg_echo('plugins:search:noresults:info');
}

// Add the list of plugins to the main area
$main .= elgg_view('plugins/search/main', array('area1' => $list));

$body = elgg_view_layout('one_sidebar', array(
	'title' => $heading,
	'content' => $main, 
	'sidebar' => $sidebar,
));

echo elgg_view_page($title, $body);