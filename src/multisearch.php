<?php


# Class to create a search page supporting simple search and advanced search
class multisearch
{
	# Specify available arguments as defaults or as NULL (to represent a required argument)
	var $defaults = array (
		'databaseConnection'			=> NULL,
		'baseUrl'						=> NULL,
		'database'						=> NULL,
		'table'							=> NULL,
		'description'					=> 'catalogue',	// As in, "search the %description"
		'keyField'						=> 'id',
		'mainSubjectField'				=> NULL,
		'excludeFields'					=> array (),	// Fields should not appear in the search form
		'showFields'					=> NULL,
		'recordLink'					=> NULL,
		'paginationRecordsPerPage'		=> 50,
		'geographicSearchEnabled'		=> false,		// Enables GeoJSON binding - specify the fieldname in POST, or false to disable
		'geographicSearchMapUrl'		=> '/',
		'geographicSearchField'			=> 'geometry',	// Spatial database field for location search
		'geographicSearchTrueWithin'	=> FALSE,	// If the trueWithin function is available in SQL
		// 'queryArgSeparator'			=> ',',
	);
	
	
	# Class properties
	private $settings = array ();
	private $html = '';
	private $baseUrl = '';
	
	
	# Search functionality
	public function __construct ($settings)
	{
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
		$html  = "\n<h2>Search</h2>";
		
		# Get the database fields
		$fields = $this->databaseConnection->getFields ($this->settings['database'], $this->settings['table'], $addSimpleType = true);
		
		/* This area is difficult to make work in practice
		# Emulate $_GET for the query string section
		if (isSet ($_GET['query']) && strlen ($_GET['query'])) {
			parse_str ($_GET['query'], $get);	// Takes account of arg_separator.output but that has to be set at INI_PERDIR
			unset ($_GET['query']);
		}
		*/
		
		# If there is a set of GET data (which is not checked for matching fields, as they could change over time), do the search; otherwise show the form
		$get = $_GET;
		unset ($get['action']);
		if ($get) {
			$html .= $this->searchResult ($get, $fields);
		} else {
			$html .= $this->searchForm ($fields);
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Search form wrapper function
	private function searchForm ($fields, $data = array (), $simpleHasAutofocus = true)
	{
		# Add the simple form, ending if false (indicating a redirect) is returned
		if (!$searchFormSimple		= $this->searchFormSimple ($fields, $data, $simpleHasAutofocus)) {return false;}
		
		# Add GeoJSON support if required
		if ($this->settings['geographicSearchEnabled']) {
			if (!$searchFormByLocation		= $this->searchFormByLocation ()) {return false;}
		} else {
			$searchFormByLocation = false;
		}
		
		# Add the advanced form
		if (!$searchFormAdvanced	= $this->searchFormAdvanced ($fields, $data, !$simpleHasAutofocus)) {return false;}
		
		# Compile the HTML
		$html  = $searchFormSimple;
		$html .= $searchFormByLocation;
		$html .= $searchFormAdvanced;
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to provide the search form - simple search
	private function searchFormSimple ($fields, $data = array (), $hasAutofocus = false)
	{
		# Start the HTML
		$html  = "\n<p>Use this simple box to search the {$this->settings['description']}. It will search through the {$this->settings['description']} numbers and captions. For a more advanced search, see below.</p>";
		
		# Create a search form
		$template  = "\n{[[PROBLEMS]]}\n{search} {[[SUBMIT]]}";
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
		
		# Construct the HTML
		$html  = "\n<h2>Search by location</h2>";
		$html .= "\n" . sprintf ('<p>You can <a href="%s">search for items in an area</a> using the map controls.</p>', $this->settings['geographicSearchMapUrl']);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to provide the search form - advanced search
	private function searchFormAdvanced ($fields, $data = array (), $hasAutofocus = false)
	{
		# Start the HTML
		$html  = "\n<h2>Advanced search</h2>";
		$html .= "\n<p>Here you can add search terms for parts of the {$this->settings['description']} data.</p>";
		$html .= "\n<p>To search for partial names, use * for the part of a word you don't know. For instance, an ID search for <em>70h*</em> would find items beginning with '70h'.</p>";
		
		# Create the search form
		$form = new form (array (
			'displayRestrictions' => false,
			'formCompleteText' => false,
			'databaseConnection' => $this->databaseConnection,
			'name' => false,
			// 'submitButtonPosition' => 'both',
			'submitTo' => $this->baseUrl,
		));
		
		# Define the dataBinding attributes
		$dataBindingAttributes = array (
			$this->settings['keyField'] => array ('autofocus' => $hasAutofocus, ),
		);
		
		# Prevent required fields
		foreach ($fields as $fieldname => $field) {
			if ($field['Null'] == 'NO') {
				$dataBindingAttributes[$fieldname]['required'] = false;
			}
		}
		
		# Databind the form
		$form->dataBinding (array (
			'database' => $this->settings['database'],
			'table' => $this->settings['table'],
			'exclude' => $this->settings['excludeFields'],
			'attributes' => $dataBindingAttributes,
			'data' => $data,
		));
		
		# Obtain the result
		if ($result = $form->process ($html)) {
			
			# Filter to include only those where the user has specified a value
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
	
	
	# Function to do getData via pagination
	private function getDataViaPagination ($query, $associative = false, $keyed = true, $preparedStatementValues = array (), $paginationRecordsPerPage)
	{
		# Prepare the counting query
		$placeholders = array (
			'/^SELECT (?! FROM ).+ FROM/' => 'SELECT COUNT(*) AS total FROM',
			'/;$/' => ';',
		);
		$queryCount = preg_replace (array_keys ($placeholders), array_values ($placeholders), $query);
		
		# Perform a count first; use a negative look-around to match the section between SELECT ... FROM - see http://stackoverflow.com/questions/406230
		$dataCount = $this->databaseConnection->getOne ($queryCount);
		$total = $dataCount['total'];
		
		# Get the requested page and calculate the pagination
		require_once ('application.php');
		$requestedPage = ((isSet ($_GET['page']) && ctype_digit ($_GET['page'])) ? $_GET['page'] : 1);
		list ($totalPages, $offset, $items, $limit, $page) = application::getPagerData ($total, $paginationRecordsPerPage, $requestedPage);
		
		# Now construct the main query
		$placeholders = array (
			'/^SELECT (?! FROM ).+ FROM/' => 'SELECT * FROM',
			'/;$/' => " LIMIT {$offset}, {$limit};",
		);
		$queryData = preg_replace (array_keys ($placeholders), array_values ($placeholders), $query);
		
		# Get the data
		$data = $this->databaseConnection->getData ($queryData, $associative, $keyed, $preparedStatementValues);
		
		# Return the data and the total
		return array ($data, $total);
	}
	
	
	
	# Function to do the search
	private function searchResult ($result, $fields)
	{
		# Start the HTML
		$html  = '';
		
		# Determine if this is a simple search (i.e. an array as strictly array('search'=>value), compile the search phrases
		$isSimpleSearch = ($result && (count ($result) == 1) && (isSet ($result['search'])));
		
		# Determine if this is a geographic search
		$geographicSearchField = $this->settings['geographicSearchField'];
		$isGeographicSearch = ($result && (count ($result) == 1) && (isSet ($result[$geographicSearchField])));
		
		# Get the search clauses
		if ($isSimpleSearch) {
			$searchClauses = $this->searchClausesSimple ($result, $fields);
			$searchClausesSql = implode (' OR ', $searchClauses);
		} else {
			$searchClauses = $this->searchClausesAdvanced ($result, $fields);
			$searchClausesSql = implode (' AND ', $searchClauses);
		}
		
		# Construct the query
		$query = "SELECT * FROM {$this->settings['database']}.{$this->settings['table']} WHERE " . $searchClausesSql . " ORDER BY natsort;";
		list ($data, $totalAvailable) = $this->getDataViaPagination ($query, false, true, array (), $this->settings['paginationRecordsPerPage']);
		
		# End if no results
		if (!$data) {
			$html .= "\n<p>No items were found.</p>";
		} else {
			
			# Show the count
			$total = count ($data);
			$html .= "\n<p>There " . ($totalAvailable == 1 ? 'is one item' : "are {$totalAvailable} items") . '.' . ($totalAvailable == $total ? '' : ($total == 1 ? ' One is shown.' : " {$total} are shown.")) . '</p>';
			
			# Create a table of items
			foreach ($data as $index => $record) {
				$id = $record[$this->settings['keyField']];
				foreach ($record as $key => $value) {
					if (!in_array ($key, $this->settings['showFields'])) {
						unset ($data[$index][$key]);
					}
				}
			}
			
			# Make all data entity-safe (as the table will not be doing this directly)
			foreach ($data as $index => $record) {
				$id = $record[$this->settings['keyField']];
				foreach ($record as $key => $value) {
					$data[$index][$key] = htmlspecialchars ($data[$index][$key]);
				}
			}
			
			# Create a link to the item
			foreach ($data as $index => $record) {
				$recordLink = $this->settings['recordLink'];
				foreach ($record as $key => $value) {
					$recordLink = str_replace ("%lower({$key})", urlencode (strtolower ($value)), $recordLink);
					$recordLink = str_replace ("%{$key}", urlencode ($value), $recordLink);
				}
				$recordLink = htmlspecialchars ($recordLink);
				$data[$index][$this->settings['keyField']] = "<a href=\"{$recordLink}\">" . $record[$this->settings['keyField']] . '</a>';
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
		if ($isGeographicSearch) {
			$html .= "\n" . sprintf ('<p><a href="%s">Define a different map area</a> if you wish, or try a <a href="' . $this->baseUrl . '">text search</a>.</p>', $this->settings['geographicSearchMapUrl']);
		} else {
			$html .= "\n" . '<p><a id="showform" name="showform">Refine this search</a> if you wish.</p>';
		}
		$html .= "\n" . '<div id="searchform">';
		$html .= $this->searchForm ($fields, $result, $isSimpleSearch);
		$html .= "\n" . '</div>';
		
		# Create the table
		if ($data) {
			$headings = $this->databaseConnection->getHeadings ($this->settings['database'], $this->settings['table']);
			$html .= "\n" . '<!-- Enable table sortability: --><script language="javascript" type="text/javascript" src="http://www.geog.cam.ac.uk/sitetech/sorttable.js"></script>';
			$html .= application::htmlTable ($data, $headings, $class = 'searchresult lines sortable" id="sortable', $keyAsFirstColumn = false, false, $allowHtml = true);
		}
		
		# Return the HTML
		return $html;
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
			$html  = "\n<p>No valid search parameters were supplied. Please <a href=\"{$this->baseUrl}/search/\">try a new search</a>.</p>";
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
						if ($this->settings['geographicSearchTrueWithin']) {
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