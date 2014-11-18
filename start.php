<?php

namespace community\solr;

elgg_register_event_handler('init', 'system', __NAMESPACE__ . '\\init');

function init() {
	// remove the default plugin hook
	elgg_unregister_plugin_hook_handler('search', 'object:plugin_project', 'plugins_search_hook');
	
	elgg_register_plugin_hook_handler('search', 'object:plugin_project', __NAMESPACE__ . '\\plugin_search');
	elgg_register_plugin_hook_handler('elgg_solr:index', 'object', __NAMESPACE__ . '\\plugin_index');
	elgg_register_plugin_hook_handler('route', 'plugins', __NAMESPACE__ . '\\plugins_router');
}


function plugin_search($hook, $type, $return, $params) {

    $select = array(
        'start'  => $params['offset'],
        'rows'   => $params['limit'],
        'fields' => array('id','title','description')
    );

    // create a client instance
    $client = elgg_solr_get_client();

    // get an update query instance
    $query = $client->createSelect($select);
	$query->addSorts(array(
		'score' => 'desc',
		'time_created' => 'desc'
	));
	
	$title_boost = elgg_solr_get_title_boost();
	$description_boost = elgg_solr_get_description_boost();
	
	// get the dismax component and set a boost query
	$dismax = $query->getDisMax();
	$dismax->setQueryFields("title^{$title_boost} description^{$description_boost}");
	$dismax->setQueryAlternative('*:*');
	
	$boostQuery = elgg_solr_get_boost_query();
	if ($boostQuery) {
		$dismax->setBoostQuery($boostQuery);
	}
	
	// this query is now a dismax query
	$query->setQuery($params['query']);
	
	// make sure we're only getting objects:plugin_project
	$params['fq']['type'] = 'type:object';
	$params['fq']['subtype'] = 'subtype:plugin_project';
	
	if (($category = get_input('category')) && ($category != 'all')) {
		$params['fq']['plugincat'] = 'tags:"' . elgg_solr_escape_special_chars('plugincat%%' . $category) . '"';
	}

	$default_fq = elgg_solr_get_default_fq($params);
	if ($params['fq']) {
		$filter_queries = array_merge($default_fq, $params['fq']);
	}
	else {
		$filter_queries = $default_fq;
	}

    if (!empty($filter_queries)) {
        foreach ($filter_queries as $key => $value) {
            $query->createFilterQuery($key)->setQuery($value);
        }
    }

    // get highlighting component and apply settings
    $hl = $query->getHighlighting();
    $hl->setFields(array('title', 'description'));
    $hl->setSimplePrefix('<strong class="search-highlight search-highlight-color1">');
    $hl->setSimplePostfix('</strong>');

    // this executes the query and returns the result
    try {
        $resultset = $client->select($query);
    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }

    // Get the highlighted snippet
    try {
        $highlighting = $resultset->getHighlighting();
    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }

    // Count the total number of documents found by solr
    $count = $resultset->getNumFound();

    $search_results = array();
    foreach ($resultset as $document) {
		$search_results[$document->id] = array();
		$snippet = '';
            
		// highlighting results can be fetched by document id (the field defined as uniquekey in this schema)
        $highlightedDoc = $highlighting->getResult($document->id);

        if($highlightedDoc){
            foreach($highlightedDoc as $field => $highlight) {
                $snippet = implode(' (...) ', $highlight);
				$snippet = search_get_highlighted_relevant_substrings(elgg_strip_tags($snippet), $params['query']);
				$search_results[$document->id][$field] = $snippet;
            }
        }
    }
	
	// get the entities
	$entities = array();
	$entities_unsorted = array();
	if ($search_results) {
		$entities_unsorted = elgg_get_entities(array(
			'guids' => array_keys($search_results),
			'limit' => false
		));
	}
	
	foreach ($search_results as $guid => $matches) {
		foreach ($entities_unsorted as $e) {
			if ($e->guid == $guid) {
				if ($matches['title']) {
					$e->setVolatileData('search_matched_title', $matches['title']);
				}
				else {
					$e->setVolatileData('search_matched_title', $e->title);
				}
				
				if ($matches['description']) {
					$e->setVolatileData('search_matched_description', $matches['description']);
				}
				else {
					$e->setVolatileData('search_matched_description', elgg_get_excerpt($e->description, 100));
				}
				$entities[] = $e;
			}
		}
	}

    return array(
        'entities' => $entities,
        'count' => $count,
    );
}



function plugin_index($h, $t, $doc, $p) {
	if (!elgg_instanceof($p['entity'], 'object', 'plugin_project')) {
		return $doc;
	}
	
	$entity = $p['entity'];
	
	// add brief description to the full description
	$description = trim($entity->summary . ' ' . $entity->description);
	$doc->description = $description;
	
	// use generic fields for some filter queries
	// eg.
	// elgg_string1 = elgg_version
	$releases = $entity->getReleases(array('limit' => false));
	$elgg_versions = array();
	if ($releases) {
		foreach ($releases as $release) {
			if (is_array($release->elgg_version)) {
				foreach ($release->elgg_version as $version) {
					$elgg_versions[] = $version;
				}
			}
			else {
				$elgg_versions[] = $release->elgg_version;
			}
		}
	}
	
	$screenshots = $entity->getScreenshots();
	
	$doc->elgg_string1 = array_unique($elgg_versions);
	$doc->elgg_string2 = $entity->plugin_type;
	$doc->elgg_string3 = $entity->plugincat;
	$doc->elgg_string4 = $entity->license;
	$doc->elgg_string5 = $screenshots ? 1 : 0;
	
	// store category with the tags
	$categoryname = 'plugincat';
	$tag_registered = false;
	$md = elgg_get_registered_tag_metadata_names();
	if ($md && in_array($categoryname, $md)) {
		$tag_registered = true;
	}
	
	if (!$tag_registered) {
		elgg_register_tag_metadata_name($categoryname);
	}
	
	$doc->tags = elgg_solr_get_tags_array($entity);
	
	if (!$tag_registered) {
		// we should unregister it now in case anything else
		// wants to use registered tag names
		elgg_set_config('registered_tag_metadata_names', $md);
	}
	
	return $doc;
}


function plugins_router($h, $t, $r, $p) {
	if ($r['segments'][0] == 'search' && !isset($r['segments'][1])) {
		include __DIR__ . '/pages/plugins/search.php';
		return false;
	}
	
	return $r;
}
