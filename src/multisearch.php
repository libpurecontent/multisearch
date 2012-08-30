<?php

# Version 1.0.2


# Class to create a search page supporting simple search and advanced search
class multisearch
{
	# Specify available arguments as defaults or as NULL (to represent a required argument)
	var $defaults = array (
		'databaseConnection'				=> NULL,
		'baseUrl'							=> NULL,
		'database'							=> NULL,
		'table'								=> NULL,
		'dataBindingParameters'				=> array (),
		'description'						=> 'catalogue',	// As in, "search the %description"
		'keyField'							=> 'id',
		'orderBy'							=> 'id',
		'enableSimpleSearch'				=> true,
		'mainSubjectField'					=> NULL,
		'excludeFields'						=> array (),	// Fields should not appear in the search form or the search results table
		'showFields'						=> NULL,
		'ignoreKeys'						=> array (),	// Defined query string parameters to ignore, e.g. values from a surrounding application's query string
		'recordLink'						=> NULL,
		'paginationRecordsPerPage'			=> 50,
		'enumRadiobuttons'					=> 1,
		'enumRadiobuttonsInitialNullText'	=> array (),	// e.g. array ('foo' => 'Either') which would put "Either" as the empty enum text at the start of widget 'foo'
		'searchResultsMaximumLimit'			=> false,
		'geographicSearchEnabled'			=> false,		// Enables GeoJSON binding - specify the fieldname in POST, or false to disable
		'geographicSearchMapUrl'			=> '/?mode=draw',
		'geographicSearchField'				=> 'geometry',	// Spatial database field for location search
		'geographicSearchTrueWithin'		=> true,	// If the trueWithin function is available in SQL
		// 'queryArgSeparator'				=> ',',
		'exportingEnabled'					=> true,	// Whether CSV export is enabled
		'exportingFieldLabels'				=> false,	// Whether to use field labels if available rather than the field names when exporting
		'codings'							=> false,	// Codings (lookups of data in the table)
		'jQueryLoaded'						=> false,	// Whether jQuery has already been loaded
		'headingLevel'						=> 2,
		'resultsContainerClass'				=> 'boxed',
		'resultRenderer'					=> false,	// Result renderer, as a callable function, i.e. array(class,method)
	);
	
	
	# Class properties
	private $settings = array ();
	private $html = '';
	private $baseUrl = '';
	
	
	# Search functionality
	public function __construct ($settings)
	{
		# Load required libraries
		require_once ('application.php');
		require_once ('database.php');
		//require_once ('jquery.php');	// Added below
		require_once ('ultimateForm.php');
		
		# Merge in the arguments; note that $errors returns the errors by reference and not as a result from the method
		if (!$this->settings = application::assignArguments ($errors, $settings, $this->defaults, __CLASS__, NULL, $handleErrors = true)) {
			return false;
		}
		
		# Global the settings
		$this->databaseConnection = $this->settings['databaseConnection'];
		$this->baseUrl = $this->settings['baseUrl'];
		
		# Run the main function
		$this->main ();
	}
	
	
	# Getter to get the HTML
	public function getHtml ()
	{
		return $this->html;
	}
	
	
	
	# Main function
	private function main ()
	{
		# Start the HTML
		$html  = '';
		
		# Add the heading if required
		if ($this->settings['headingLevel']) {
			$html .= "\n<h{$this->settings['headingLevel']}>Search</h{$this->settings['headingLevel']}>";
		}
		
		# Get the database fields
		$fields = $this->databaseConnection->getFields ($this->settings['database'], $this->settings['table'], $addSimpleType = true);
		
		/* This area is difficult to make work in practice
		# Emulate $_GET for the query string section
		if (isSet ($_GET['query']) && strlen ($_GET['query'])) {
			parse_str ($_GET['query'], $get);	// Takes account of arg_separator.output but that has to be set at INI_PERDIR
			unset ($_GET['query']);
		}
		*/
		
		# Copy the GET data
		$get = $_GET;
		
		# Clear out defined query string parameters, e.g. values from a surrounding application's query string
		if ($this->settings['ignoreKeys'] && is_array ($this->settings['ignoreKeys'])) {
			foreach ($this->settings['ignoreKeys'] as $key) {
				if (array_key_exists ($key, $get)) {
					unset ($get[$key]);
				}
			}
		}
		
		# Determine if there is to be a geometry field
		$geometry = $this->getGeometryFromRequest ();
		
		# If there is a set of GET data (which is not checked for matching fields, as they could change over time), do the search; otherwise show the form
		unset ($get['action']);
		if ($get) {
			$result = $this->searchResult ($get, $fields, $geometry);
			if ($result === true) {		// i.e. export format
				return true;
			} else {
				$html .= $result;
			}
		} else {
			$html .= $this->searchForm ($fields, array (), true, $geometry);
		}
		
		# Register the HTML
		$this->html = $html;
	}
	
	
	# Search form wrapper function
	private function searchForm ($fields, $data = array (), $simpleHasAutofocus = true, $geometry = false)
	{
		# Do a geography-only search if required
		if ($this->settings['geographicSearchEnabled']) {
			if (!$this->searchFormByLocation ()) {return false;}
		}
		
		# Start a list of forms
		$forms = array ();
		
		# Add the simple form, ending if false (indicating a redirect) is returned
		if ($this->settings['enableSimpleSearch']) {
			if (!$searchFormSimple = $this->searchFormSimple ($fields, $data, $simpleHasAutofocus, $geometry)) {return false;}
			$forms['simple'] = $searchFormSimple;
		}
		
		# Add the advanced form
		if (!$searchFormAdvanced = $this->searchFormAdvanced ($fields, $data, !$simpleHasAutofocus, $geometry)) {return false;}
		$forms['advanced'] = $searchFormAdvanced;
		
		# Compile the HTML, adding tabs if more than one form type enabled
		if (count ($forms) == 1) {
			$html = $forms['advanced'];
		} else {
			$html = implode ($forms);
			
			# Define the tabs
			$labels = array (
				'simple' => 'Simple keyword search',
				'advanced' => 'Advanced search',
			);
			
			# Load into tabs
			require_once ('jquery.php');
			$jQuery = new jQuery (false, false, false, $this->settings['jQueryLoaded']);
			$jQuery->tabs ($labels, $forms, $switchToTabNumber = '0', false, $this->settings['resultsContainerClass']);
			$html  = $jQuery->getHtml ();
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get the geometry from a request
	private function getGeometryFromRequest ()
	{
		# Return false if not enabled
		if (!$this->settings['geographicSearchEnabled']) {return false;}
		
		# Prefer posted data
		if (isSet ($_POST[$this->settings['geographicSearchField']])) {
			return $_POST[$this->settings['geographicSearchField']];
		}
		
		# Otherwise try GET
		if (isSet ($_GET[$this->settings['geographicSearchField']])) {
			return $_GET[$this->settings['geographicSearchField']];
		}
		
		# No data
		return false;
	}
	
	# Function to provide the search form - simple search
	private function searchFormSimple ($fields, $data = array (), $hasAutofocus = false, $geometry = false)
	{
		# Start the HTML
		$headingLevel = ($this->settings['headingLevel'] ? $this->settings['headingLevel'] + 1 : 3);
		$html  = "\n<h{$headingLevel}>Simple keyword search</h{$headingLevel}>";
		$html .= "\n<p>Use this simple box to search the {$this->settings['description']}. It will search through the {$this->settings['description']} numbers and captions. For a more advanced search, see the other tab.</p>";
		
		# Create a search form
		$template  = "\n{[[PROBLEMS]]}\n{search} {[[SUBMIT]]}";
		if ($this->settings['geographicSearchEnabled']) {
			$template .= "\n<p>{{$this->settings['geographicSearchField']}}</p>";
		}
		$form = new form (array (
			'displayRestrictions' => false,
			'formCompleteText' => false,
			'display' => 'template',
			'displayTemplate' => $template,
			'div' => 'largesearch',
			'submitButtonText' => 'Search!',
			'submitButtonAccesskey' => false,
			'requiredFieldIndicator' => false,
			// 'reappear' => true,
			'name' => false,
			'submitTo' => $this->baseUrl,
		));
		
		# Add geometry field if required
		if ($this->settings['geographicSearchEnabled']) {
			$this->formGeometryField ($form, $geometry);
		}
		
		# Main search box
		$form->search (array (
			'name'			=> 'search',
			'title'			=> 'Search for:',
			'required'		=> true,
			'minlength'		=> 3,
			'size'			=> 50,
			'autofocus'		=> $hasAutofocus,
			'default'		=> (isSet ($data['search']) ? $data['search'] : false),
		));
		if ($result = $form->process ($html)) {
			
			# Redirect so that the search parameters can be persistent
			$url = $this->queryToUrl ($result);
			application::sendHeader (302, $url);
			return false;
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Helper function to convert a search query to a persistent URL
	private function queryToUrl ($result)
	{
		//ini_set ('arg_separator.output', $this->settings['queryArgSeparator']);
		//return $url = $_SERVER['_SITE_URL'] . $this->baseUrl . http_build_query ($result) . '/';
		return $url = $_SERVER['_SITE_URL'] . $this->baseUrl . '?' . http_build_query ($result);
	}
	
	
	# Function to provide a search form that handles GeoJSON format
	private function searchFormByLocation ()
	{
		# Retrieve the data if submitted
		$fieldname = $this->settings['geographicSearchEnabled'];
		if (isSet ($_POST[$fieldname])) {
			
			# Decode the JSON to an array
			$jsonArray = json_decode ($_POST[$fieldname], true);
			
			# Check the structure, that it is a Polygon, and retrieve the co-ordinates portion only
			$coordinatesSet = false;
			if ($jsonArray) {
				if (isSet ($jsonArray['geometry'])) {
					if (isSet ($jsonArray['geometry']['type'])) {
						if ($jsonArray['geometry']['type'] == 'Polygon') {	// Currently support only Polygon type
							if (isSet ($jsonArray['geometry']['coordinates'])) {
								if (isSet ($jsonArray['geometry']['coordinates'][0])) {
									if (is_array ($jsonArray['geometry']['coordinates'][0])) {
										$coordinatesSet = $jsonArray['geometry']['coordinates'][0];
									}
								}
							}
						}
					}
				}
			}
			
			# If there co-ordinates, convert back to JSON and use in the query
			if ($coordinatesSet) {
				
				# Encode as lon1,lat1|lon2,lat2|...
				$coordinatesJson = json_encode ($coordinatesSet);	// e.g. a string like [[3.0009179687488,53.540743120766],[2.8141503906209,52.743387092624],[2.0670800781271,52.616835414769]]
				
				# Convert an associative result
				$databaseField = $this->settings['geographicSearchField'];
				$result = array ($databaseField => $coordinatesJson);
				
				# Redirect so that the search parameters can be persistent
				$url = $this->queryToUrl ($result);
				application::sendHeader (302, $url);
				return false;
			}
		}
		
		# Return return
		return true;
	}
	
	
	# Function to provide a geometry field in the form
	private function formGeometryField (&$form, $geometry)
	{
		# Define a tick symbol
		$isOldIE = preg_match ('/MSIE [1-8]\./', $_SERVER['HTTP_USER_AGENT']);
		$tick = ($isOldIE ? '&radic;' : '&#10004;');
		
		# If a geometry is defined, retain that as a hidden field
		$form->input (array (
			'name'	=> $this->settings['geographicSearchField'],
			'title' => 'Map area',
			'default' => $geometry,
			'editable' => false,
			'displayedValue' => ($geometry ? $tick . ' <img src="/images/icons/map.png" alt="Map" border="0" />' . " Map area defined &nbsp;[or <a href=\"{$this->baseUrl}\">reset all</a>]" : "<a href=\"{$this->settings['geographicSearchMapUrl']}\">+ Set map area filter</a>"),
			'entities' => false,
		));
	}
	
	
	# Function to provide the search form - advanced search
	private function searchFormAdvanced ($fields, $data = array (), $hasAutofocus = false, $geometry = false)
	{
		# Start the HTML
		$html  = '';
		if ($this->settings['enableSimpleSearch']) {	// Don't show this heading if it is the only search
			$headingLevel = ($this->settings['headingLevel'] ? $this->settings['headingLevel'] + 1 : 3);
			$html  = "\n<h{$headingLevel}>Advanced search</h{$headingLevel}>";
		}
		$html .= "\n<p>Here you can add search terms for parts of the {$this->settings['description']} data.</p>";
		$html .= "\n<p>For partial names, use * for the part of a word/term you don't know. For instance, an ID search for <em>70*</em> would find items beginning with '70'.</p>";
		
		# Create the search form
		$form = new form (array (
			'displayRestrictions' => false,
			'formCompleteText' => false,
			'databaseConnection' => $this->databaseConnection,
			'name' => false,
			// 'submitButtonPosition' => 'both',
			'submitTo' => $this->baseUrl,
			'nullText' => false,
		));
		
		# Add geometry field if required
		if ($this->settings['geographicSearchEnabled']) {
			$this->formGeometryField ($form, $geometry);
		}
		
		# Define the dataBinding attributes
		$dataBindingAttributes = array (
			$this->settings['keyField'] => array ('autofocus' => $hasAutofocus, ),
		);
		
		# Swap in codings if supplied, by converting these to select widgets
		if ($this->settings['codings']) {
			foreach ($this->settings['codings'] as $field => $values) {
				$dataBindingAttributes[$field] = array (
					'type' => 'select',
					'values' => $values,
				);
			}
		}
		
		# Define the default dataBinding parameters
		$dataBindingParameters = array (
			'database' => $this->settings['database'],
			'table' => $this->settings['table'],
			'exclude' => $this->settings['excludeFields'],
			'attributes' => $dataBindingAttributes,
			'enumRadiobuttons' => $this->settings['enumRadiobuttons'],
			'enumRadiobuttonsInitialNullText' => $this->settings['enumRadiobuttonsInitialNullText'],
			'data' => $data,
		);
		
		# Add in any overriding dataBinding parameters, with supplied parameters taking priority; do not allow data to be supplied
		if ($this->settings['dataBindingParameters']) {
			if (isSet ($this->settings['dataBindingParameters']['data'])) {unset ($this->settings['dataBindingParameters']['data']);}
			$dataBindingParameters = $this->settings['dataBindingParameters'] + $dataBindingParameters;
		}
		
		# Prevent required fields
		foreach ($fields as $fieldname => $field) {
			if ($field['Null'] == 'NO') {
				$dataBindingParameters['attributes'][$fieldname]['required'] = false;
			}
		}
		
		# Databind the form
		$form->dataBinding ($dataBindingParameters);
		
		# Obtain the result
		if ($result = $form->process ($html)) {
			
			# Filter to include only those where the user has specified a value, to keep the URL as short as possible
			foreach ($result as $key => $value) {
				if (!strlen ($value)) {
					unset ($result[$key]);
				}
			}
			
			# Redirect so that the search parameters can be persistent
			$url = $this->queryToUrl ($result);
			application::sendHeader (302, $url);
			return false;
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to do the search
	private function searchResult ($result, $fields, $geometry)
	{
		# Start the HTML
		$html  = '';
		
		# Cache and clear out output format details if set
		$exportFormat = false;
		if (isSet ($result['exportformat'])) {
			$exportFormat = $result['exportformat'];
			unset ($result['exportformat']);
		}
		
		# Cache and clear out pagination details if set
		$page = 1;
		if (isSet ($result['page'])) {
			$page = $result['page'];
			unset ($result['page']);
		}
		
		# Determine if this is a simple search (i.e. an array as strictly array('search'=>value), compile the search phrases
		$isSimpleSearch = false;
		if ($result) {
			$keys = array_keys ($result);
			if ((count ($keys) == 2) && isSet ($result['search']) && isSet ($result['lonLat'])) {$isSimpleSearch = true;}
			if ((count ($keys) == 1) && isSet ($result['search'])) {$isSimpleSearch = true;}
		}
		
		# Get the search clauses
		$singleSearchTerm = false;
		if ($isSimpleSearch) {
			$searchClauses = $this->searchClausesSimple ($result, $fields);
			$searchClausesSql = implode (' OR ', $searchClauses);
			$singleSearchTerm = $result['search'];
		} else {
			$searchClauses = $this->searchClausesAdvanced ($result, $fields);
			$searchClausesSql = implode (' AND ', $searchClauses);
			if (count ($result) == 1) {
				$singleSearchTerms = array_values ($result);
				$singleSearchTerm = $singleSearchTerms[0];
			}
		}
		
		# Construct the query
		$datasource = "{$this->settings['database']}.{$this->settings['table']}";
		if ($geometry) {
			$datasource = $this->geographicSearchDatasourceSubquery ($this->settings['geographicSearchField'], $result[$this->settings['geographicSearchField']]);
			$singleSearchTerm = false;
		}
		$query = "SELECT * FROM {$datasource} WHERE " . $searchClausesSql . " ORDER BY {$this->settings['orderBy']};";	// NB LIMIT may be attached below
		
		# Export if required
		if ($this->settings['exportingEnabled']) {
			if ($exportFormat && $exportFormat == 'csv') {
				
				# Limit only to the maximum limit
				if ($this->settings['searchResultsMaximumLimit']) {
					$query = preg_replace ('/;$/', " LIMIT {$this->settings['searchResultsMaximumLimit']};", $query);
				}
				
				# Get the data
				$data = $this->databaseConnection->getData ($query, false, true, array (), $this->settings['showFields']);
				
				# Modify the data (e.g. excluding fields, swapping codings, etc.)
				$data = $this->modifyResults ($data);
				
				# Assign header labels if required
				$headerLabels = array ();
				if ($this->settings['exportingFieldLabels']) {
					$headerLabels = $this->databaseConnection->getHeadings ($this->settings['database'], $this->settings['table']);
				}
				
				# Serve as CSV
				require_once ('csv.php');
				csv::serve ($data, "{$this->settings['table']}_results", true, $headerLabels);
				return true;
			}
		}
		
		# Get the data via pagination
		list ($data, $totalAvailable, $totalPages, $page, $actualMatchesReachedMaximum) = $this->databaseConnection->getDataViaPagination ($query, "{$this->settings['database']}.{$this->settings['table']}", true, array (), $this->settings['showFields'], $this->settings['paginationRecordsPerPage'], $page, $this->settings['searchResultsMaximumLimit']);
		
		# Define text for a single search term
		$singleSearchTermHtml = ($singleSearchTerm ? ' matching <em>' . htmlspecialchars ($singleSearchTerm) . '</em>' : '');
		
		# End if no results
		if (!$data) {
			$html .= "\n<p>No items were found{$singleSearchTermHtml}.</p>";
		} else {
			
			# Show the count
			$total = count ($data);
			$html .= "\n<p>There " . ($totalAvailable == 1 ? 'is <strong>one</strong> item' : ($actualMatchesReachedMaximum ? 'are <strong>' . number_format ($actualMatchesReachedMaximum) . "</strong> items{$singleSearchTermHtml} <strong>but</strong> a maximum of <strong>" . number_format ($totalAvailable) . "</strong> can be shown in a search (so you may wish to refine your search below).<br />" : "are <strong>{$totalAvailable}</strong> items{$singleSearchTermHtml}. ")) . ($totalPages == 1 ? '' : "Showing {$this->settings['paginationRecordsPerPage']} records per page.") . '</p>';
			
			# Modify the data (e.g. excluding fields, swapping codings, etc.)
			$data = $this->modifyResults ($data);
			
			# Make all data entity-safe (as the table will not be doing this directly)
			foreach ($data as $recordKey => $record) {
				$id = $record[$this->settings['keyField']];
				foreach ($record as $key => $value) {
					$data[$recordKey][$key] = htmlspecialchars ($data[$recordKey][$key]);
				}
			}
			
			# Create a link to the item
			foreach ($data as $recordKey => $record) {
				$recordLink = $this->settings['recordLink'];
				foreach ($record as $key => $value) {
					$recordLink = str_replace ("%lower({$key})", urlencode (strtolower ($value)), $recordLink);
					$recordLink = str_replace ("%{$key}", urlencode ($value), $recordLink);
				}
				$recordLink = htmlspecialchars ($recordLink);
				$data[$recordKey][$this->settings['keyField']] = "<a href=\"{$recordLink}\">" . $record[$this->settings['keyField']] . '</a>';
			}
		}
		
		# Provide a link to search again, with the form present but initially hidden
		$html .= '
			<!-- http://docs.jquery.com/Effects/toggle -->
			<script src="http://code.jquery.com/jquery-latest.js"></script>
			<script>
				$(document).ready(function(){
					$("a#showform").click(function () {
						$("#searchform").toggle();
					});
				});
			</script>
			<style type="text/css">#searchform {display: none;}</style>
		';
		$html .= "\n" . '<p><a id="showform" name="showform"><img src="/images/icons/pencil.png" alt="" border="0" /> <strong>Refine/filter this search</strong></a> if you wish.</p>';
		$html .= "\n" . '<div id="searchform">';
		$html .= $this->searchForm ($fields, $result, $isSimpleSearch, $geometry);
		$html .= "\n" . '</div>';
		
		# Create the table, starting with pagination
		if ($data) {
			$queryStringComplete = http_build_query ($result);
			$paginationLinks = application::paginationLinks ($page, $totalPages, $this->baseUrl, $queryStringComplete);
			if ($this->settings['exportingEnabled']) {
				$html .= "\n<p class=\"" . ($paginationLinks ? 'right' : 'alignright') . "\"><a href=\"{$this->baseUrl}results.csv?" . htmlspecialchars ($queryStringComplete) . '"><img src="/images/fileicons/csv.gif" alt="" width="16" height="16" border="0" /> Export all to CSV (Excel)</a>' . ($paginationLinks ? ' <abbr title="This will export the full set of results for this search, not just the paginated subset below' . ($actualMatchesReachedMaximum ? ', subject to the maximum of ' . number_format ($totalAvailable) . ' items' : '') . '.">[?]</abbr>' : '') . '</p>';
			}
			$html .= $paginationLinks;
			
			# Determine the table renderer
			list ($resultRendererClass, $resultRendererMethod) = array ($this, 'resultsTable');
			if ($this->settings['resultRenderer'] && is_array ($this->settings['resultRenderer']) && (count ($this->settings['resultRenderer']) == 2)) {
				if (method_exists ($this->settings['resultRenderer'][0], $this->settings['resultRenderer'][1])) {
					list ($resultRendererClass, $resultRendererMethod) = $this->settings['resultRenderer'];
				}
			}
			
			# Render the table
			$html .= $resultRendererClass->{$resultRendererMethod} ($data, $this->settings['table']);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to create the data table
	private function resultsTable ($data, $table)
	{
		# Get the headings
		$headings = $this->databaseConnection->getHeadings ($this->settings['database'], $table);
		
		# Compile the HTML
		$html  = "\n" . '<!-- Enable table sortability: --><script language="javascript" type="text/javascript" src="http://www.geog.cam.ac.uk/sitetech/sorttable.js"></script>';
		$html .= application::htmlTable ($data, $headings, $class = 'searchresult lines sortable" id="sortable', $keyAsFirstColumn = false, false, $allowHtml = true, false, $addCellClasses = true);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to modify the output data (e.g. excluding fields, swapping codings, etc.)
	private function modifyResults ($data)
	{
		# Exclude fields if required
		if ($this->settings['excludeFields']) {
			foreach ($data as $recordKey => $record) {
				foreach ($record as $key => $value) {
					if (in_array ($key, $this->settings['excludeFields'])) {
						unset ($data[$recordKey][$key]);
					}
				}
			}
		}
		
		# Swap in codings if supplied
		if ($this->settings['codings']) {
			foreach ($data as $recordKey => $record) {
				foreach ($record as $key => $value) {
					if (isSet ($this->settings['codings'][$key]) && isSet ($this->settings['codings'][$key][$value])) {
						$data[$recordKey][$key] = $this->settings['codings'][$key][$value];
					}
				}
			}
		}
		
		# Return the data
		return $data;
	}
	
	
	# Function to get the search clauses - simple search
	private function searchClausesSimple ($result, $fields)
	{
		# Get the search term
		$term = $result['search'];
		
		# Start a list of clauses, which will search only through specific fields
		$searchClauses = array ();
		
		# For ID, require an exact match
		$searchClauses[$this->settings['keyField']] = $this->settings['keyField']  . ' = ' . $this->databaseConnection->quote ($term);
		
		# For subject, make the result sticky to word boundaries, but do not support * wildcards
		$searchClauses[$this->settings['mainSubjectField']] = $this->textSearchClauseRespectingWordBoundaries ($this->settings['mainSubjectField'], $term, false);
		
		# Return the clauses array
		return $searchClauses;
	}
	
	
	# Function to get the search clauses - advanced search
	private function searchClausesAdvanced ($result, $fields)
	{
		# Filter to supported fields only
		foreach ($result as $key => $value) {
			if (!isSet ($fields[$key])) {
				unset ($result[$key]);
			}
		}
		
		# End if no approved search parameters
		if (!$result) {
			$html  = "\n<p>No valid search parameters were supplied. Please <a href=\"{$this->baseUrl}\">try a new search</a>.</p>";
			return $html;
		}
		
		# Determine the appropriate search strategy for each field
		$searchClauses = array ();
		foreach ($result as $key => $value) {
			
			# For the special-case of the main key field, require a direct match, but support *
			if ($key == $this->settings['keyField']) {
				$value = str_replace ('*', '%', $value);	// Replace \* (which was originally *) with (.+)
				$searchClauses[$key] = "{$key} LIKE " . $this->databaseConnection->quote ($value);
				continue;	// Skip to next, rather than running the switch
			}
			
			# Otherwise, select the strategy based on the field type
			switch ($fields[$key]['_type']) {	// Simplified type
				
				case 'string':
				case 'text':
					$searchClauses[$key] = $this->textSearchClauseRespectingWordBoundaries ($key, $value, true);
					break;
					
				case 'numeric':
				case 'date':
					$searchClauses[$key] = "{$key} = " . $this->databaseConnection->quote ($value);
					break;
					
				case 'list':
					$searchClauses[$key] = "{$key} = " . $this->databaseConnection->quote ($value);
					break;
					
				case 'point':
					if ($geom = $this->jsonPolygonToGeom ($value)) {
						if ($this->settings['geographicSearchTrueWithin']) {	// Note also the geographicSearchDatasourceSubquery() to prefilter the dataset first
							$searchClauses[$key] = "trueWithin({$key}, " . $geom . ')';	// See trueWithin in within.sql from CycleStreets codebase
						} else {
							$searchClauses[$key] = "MBRContains(" . $geom . ", {$key})";	// See http://dev.mysql.com/doc/refman/4.1/en/relations-on-geometry-mbr.html
						}
					} else {
						$searchClauses[$key] = '1=0';	// Simple way of ensuring the query fails if the Geom string is invalid
					}
					break;
			}
		}
		
		# Return the search clauses
		return $searchClauses;
	}
	
	
	# Function to provide a subquery for geographic search
	private function geographicSearchDatasourceSubquery ($key, $value)
	{
		# Optimise based on the database vendor
		switch ($this->databaseConnection->vendor) {
			
			// MySQL has poor MBRContains()/Within() support, so we use a subquery to cut down the initial table size to a smaller bounding box first
			case 'mysql':
				if ($this->settings['geographicSearchTrueWithin']) {	// Don't do this if there is no true Within() function available
					if ($geom = $this->jsonPolygonToGeom ($value)) {
						$table = "(
							SELECT
							*
							FROM {$this->settings['database']}.{$this->settings['table']}
							WHERE MBRContains(" . $geom . ", {$key})
						) AS mbrPrefiltered";
						return $table;
					}
				}
				break;
				
			// PostgreSQL not yet determined - probably won't need any optimisation
			default:
				break;
		}
		
		# Fallback is to return the standard table setting
		$default = "{$this->settings['database']}.{$this->settings['table']}";
		return $default;
	}
	
	
	# Helper function to convert a GeoJSON string to a Geom string
	private function jsonPolygonToGeom ($value)
	{
		# Decode
		$coordinatesSet = json_decode ($value);
		
		# Convert the associative array to Geom format, e.g. Polygon((0 0,90 0,0 90,90 90))
		$coordinateGroups = array ();
		foreach ($coordinatesSet as $coordinates) {
			$coordinateGroups[] = implode (' ', $coordinates);
		}
		$coordinatesString = implode (',', $coordinateGroups);
		
		# Ensure the string contains only valid characters, to thwart SQL injection attacks
		if (!preg_match ('/^([- .,0-9]+)$/', $coordinatesString)) {return false;}
		
		# Compile the Geom string
		$geomString = "GeomFromText('Polygon((" . $coordinatesString . "))')";
		
		# Return the string
		return $geomString;
	}
	
	
	# Function to provide a text search clause which is sticky to word boundaries
	private function textSearchClauseRespectingWordBoundaries ($fieldname, $term, $supportWildcard = false)
	{
		# Ensure that the user cannot specify a regexp as such
		$term = preg_quote ($term);
		
		# If wildcards are supported, replace \* (which was originally *) with (.+)
		if ($supportWildcard) {
			$term = str_replace ('\\*', '(.*)', $term);
		}
		
		# Apply word boundary markers
		$term = '[[:<:]]' . $term . '[[:>:]]';
		
		# Compile the clause, using REGEXP, not LIKE, so that word boundaries are supported, and quote the text
		$clause = "{$fieldname} REGEXP " . $this->databaseConnection->quote ($term);
		
		# Return the compiled clause
		return $clause;
	}
}

?>