<?php

# Class to deal with interactions with the Cambridge University search engine
# Version 1.1.1
# http://download.geog.cam.ac.uk/projects/camunisearch/
# Licence: GPL
class camUniSearch
{
	/* NOTES:
	
	XML retrieval:
		SimpleXML has been used rather than the DOM method. However it does not support document-orientated data, so the search highlighting is removed below.
		If refactoring this code to the DOM method, the following pages may be of particular interest:
			http://www.php.net/xml_parse_into_struct
			http://blog.phpdeveloper.org/?p=16
		
	Cam Uni documentation:
		Some documentation is available: at http://www.ucs.cam.ac.uk/web-search/searchforms
	*/
	
	# Class properties
	private $html = '';
	
	
	# Wrapper function to process API search results
	function __construct ($site = false, $div = 'searchform', $echoHtml = true, $queryTermField = 'query')
	{
		# Load required libraries
		require_once ('application.php');
		require_once ('pureContent.php');	// Contains highlightSearchTerms
		require_once ('xml.php');
		
		# Define the base URL of this application
		$this->baseUrl = $_SERVER['SCRIPT_NAME'];
		
		# Default to the present site if none supplied
		if (!$site) {$site = $_SERVER['SERVER_NAME'];}
		
		# Allow query terms
		$query = (isSet ($_GET[$queryTermField]) ? $_GET[$queryTermField] : '');
		$offset = (isSet ($_GET['offset']) ? $_GET['offset'] : '');
		
		# Show the form
		$this->html .= "\n<div id=\"{$div}\">";
		$this->html .= "\n\t" . '<form method="get" action="" name="f">';
		$this->html .= "\n\t\t" . '<input name="' . $queryTermField . '" type="text" value="' . ($query ? htmlspecialchars ($query) : '') . "\" size=\"40\" placeholder=\"Search\" />";
		$this->html .= "\n\t\t" . '<input type="submit" value="Search" accesskey="s" />';
		$this->html .= "\n\t" . '</form>';
		$this->html .= "\n" . '</div>';
		
		# If a query term has been supplied, also show the results
		if ($query) {
			
			# Decode the parameters
			$query = urlencode ($query);
			$offset = urlencode ($offset);
			
			# Default to the present site if none supplied
			if (!$site) {$site = $_SERVER['SERVER_NAME'];}
			
			# Determine any credential of the requesting user which indicates they are internal, so that internal results can be included
			$internal = $this->userIsInternal ();
			
			# Define the location of the XML query result
			$queryUrl = "http://api.search.cam.ac.uk/" . ($internal ? 'ultraseek-xml-cam-only' : 'ultraseek-xml') . "?query={$query}" . "&include={$site}&filterTitle=Website" . ($internal ? "&endUserCrsid={$internal}" : '') . ($offset ? "&offset={$offset}" : '');
			
			/*
			# Set the stream context
			$contextOptions = array ('http' => array ('method' => 'GET', 'header' => "Content-Type: text/xml; charset=utf-8\r\n"));
			$streamContext = stream_context_create ($contextOptions);
			*/
			
			# Get the XML
			ini_set ('default_socket_timeout', 5);	// 5 second limitation
			if (!$string = @file_get_contents ($queryUrl /*, false, $streamContext */)) {
				#!# Report to admin?
				$this->html .= "\n<p class=\"warning\">Unfortunately, there was a problem retrieving the search results - apologies. Please try again later.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			//echo mb_detect_encoding ($string);
			//application::dumpData ($queryUrl, 1);
			// echo ("<!-- $string -->");
			
			# Remove search highlighting (this avoids having data-centric documents)
			$string = str_replace (array ('<highlight>', '</highlight>'), '', $string);
			
			# Get the results and convert to an array
			if (!$xmlobject = simplexml_load_string ($string, NULL, LIBXML_NOENT)) {
				#!# Report to admin?
				$this->html .= "\n<p class=\"warning\">Unfortunately, there was a problem processing the search results - apologies. Please try again later.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Convert to an array
			if (!$results = xml::simplexml2array ($xmlobject, $getAttributes = true, false)) {
				#!# Report to admin?
				$this->html .= "\n<p class=\"warning\">Unfortunately, there was a problem processing the search results - apologies. Please try again later.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Deal with pagination
			$first = $results['results']['@']['first'];
			$last = $results['results']['@']['last'];
			$total = $results['results']['@']['total'];
			
			# Get the number of results, or end if none
			if (!$total) {
				
				# See if a suggestion was made
				$suggestion = false;
				if (isSet ($results['results']['spell']) && isSet ($results['results']['spell']['suggestion'])) {
					$suggestion = trim (str_replace ("site:{$site}", '', $results['results']['spell']['suggestion']));
				}
				
				# Tell the user
				$this->html .= "\n<p>No items were found." . ($suggestion ? " Did you perhaps mean <em><a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($suggestion) . "\">" . htmlspecialchars ($suggestion) . "</a></em>?" : '') . '</p>';
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Define the navigation links
			$offsetPrevious = $first - 1 - 10;
			$navigation['previous'] = (($offsetPrevious >= 0) ? "<a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . ($offsetPrevious > 1 ? "&amp;offset={$offsetPrevious}" : '') . "\">" . '<img src="/images/general/previous.gif" alt="Previous" width="14" height="17" /></a>' : '&nbsp;');
			$navigation['current'] = "{$first}-{$last}";
			$offsetNext = $last;
			$navigation['next'] = (($offsetNext < $total) ? "<a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . "&amp;offset={$offsetNext}\">" . '<img src="/images/general/next.gif" alt="Next" width="14" height="17" /></a>' : '&nbsp;');
			
			# Show the starting description and pagination
			$searchWords = explode ('+', $query);
			$this->html .= "\n\n<p>You searched for: <em>" . htmlspecialchars (urldecode (implode (' ', $searchWords))) . '</em>.</p>';
			
			# If there are no results (generally this happens at the high end, hence the 'about' added to the text above for total results, end here
			if (!isSet ($results['results']['result'])) {
				$this->html .= "\n<p>Sorry, no more results available for <a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . '">' . htmlspecialchars ($query) . "</a>.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Show navigation controls
			$this->html .= "\n" . application::htmlUl ($navigation, 0, 'navigationmenu', false, false, false, $liClass = true);
			$this->html .= "\n<p>" . ($total <= 10 ? ($total == 1 ? 'There is one result' : "There are {$total} results") : "Showing results {$first}-{$last} of " . number_format ($total)) . ":</p>";
			
			# If there is a single result, reorganise the data
			#!# This is ultimately a problem with simplexml2array but it's acting correctly as it can't otherwise know that a multiple result could be achieved
			if (isSet ($results['results']['result']['title'])) {
				$onlyResult = $results['results']['result'];
				unset ($results['results']['result']);
				$results['results']['result'][0] = $onlyResult;
			}
			
			# Create the list, looping through the results
			$this->html .= "\n<dl class=\"searchresults\">";
			foreach ($results['results']['result'] as $key => $result) {
				
				# Deal with character encoding
				#!# Ideally this should be in simplexml2array but that seems not to work
				$result['title'] = htmlspecialchars ($result['title']);
				$result['summary'] = htmlspecialchars ($result['summary']);
				
				# Highlight search terms
				$result['title'] = highlightSearchTerms::replaceHtml ($result['title'], $searchWords, 'referer', $sourceAsTextOnly = false, $showIndication = false);
				$result['summary'] = highlightSearchTerms::replaceHtml ($result['summary'], $searchWords, 'referer', $sourceAsTextOnly = true, $showIndication = false);
				
				# Show the results
				$this->html .= "
				<dt><a href=\"{$result['@']['href']}\">" . $result['title'] . "</a></dt>
					<dd class=\"description\">" . $result['summary'] . "</dd>
					<dd class=\"attributes\"><a href=\"{$result['@']['href']}\">{$result['@']['href']}</a> - {$result['size']} <!-- {$result['date']} - Relevance: {$result['score']}--></dd>
				";
			}
			$this->html .= "\n</dl>";
		}
		
		# Show the HTML if required
		if ($echoHtml) {echo $this->html;}
	}
	
	
	# Function to determine if the user is internal and to return the relevant credential
	private function userIsInternal ()
	{
		# If logged-in with Raven, return their username
		if (isSet ($_SERVER['AUTH_TYPE']) && ($_SERVER['AUTH_TYPE'] == 'Ucam-WebAuth')) {
			if (isSet ($_SERVER['REMOTE_USER']) && strlen ($_SERVER['REMOTE_USER'])) {
				return $_SERVER['REMOTE_USER'];
			}
		}
		
		# If within the cam domain, return their IP
		$dns = gethostbyaddr ($_SERVER['REMOTE_ADDR']);
		if (preg_match ('/\.cam\.ac\.uk$/', $dns)) {
			return 'ip:' . $_SERVER['REMOTE_ADDR'];
		}
		
		# Not internal
		return false;
	}
	
	
	# Getter for the HTML
	public function getHtml ()
	{
		return $this->html;
	}
}

?>