<?php

# Class to deal with interactions with the Cambridge University search engine
class camUniSearch
{
	# Class properties
	private $html = '';
	
	
	# Wrapper function to process API search results
	function __construct ($site = false, $div = 'searchform', $echoHtml = true, $queryTermField = 'query', $include = false, $filterTitle = false)
	{
		# Define the base URL of this application
		$this->baseUrl = $_SERVER['SCRIPT_NAME'];
		
		# Default to the present site if none supplied
		if (!$site) {$site = $_SERVER['SERVER_NAME'];}
		
		# Allow query terms
		$query = (isSet ($_GET[$queryTermField]) ? $_GET[$queryTermField] : '');
		$offset = (isSet ($_GET['offset']) ? $_GET['offset'] : '');
		//$include = (isSet ($_GET['include']) ? $_GET['include'] : '');
		//$filterTitle = (isSet ($_GET['filterTitle']) ? $_GET['filterTitle'] : '');
		
		# Show the form
		$this->html .= "\n<div id=\"{$div}\">";
		$this->html .= "\n\t" . '<form method="get" action="" name="f">';
		//if ($include) {
		//	$this->html .= "\n\t\t" . '<input type="hidden" name="include" value="' . htmlspecialchars ($include) . '" />';
		//}
		//if ($filterTitle) {
		//	$this->html .= "\n\t\t" . '<input type="hidden" name="filterTitle" value="' . htmlspecialchars ($filterTitle) . '" />';
		//}
		$this->html .= "\n\t\t" . '<input name="' . $queryTermField . '" type="search" value="' . ($query ? htmlspecialchars ($query) : '') . "\" size=\"40\" placeholder=\"Search\" autofocus=\"autofocus\" />";
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
			
			# Define the location of the query result
			# See: https://docs.squiz.net/funnelback/archive/develop/programming-options/all-results-endpoint.html
			$queryUrl  = 'https://search.cam.ac.uk/search.json?';
			//$queryUrl .= 'collection=' . ($internal ? 'secure-cam-meta' : 'cam-meta');
			$queryUrl .= 'collection=cam-meta';
			$queryUrl .= "&query={$query}";
			$queryUrl .= '%20u:' . $site;
			$queryUrl .= ($offset ? "&start_rank={$offset}" : '');
			
			# Get the result
			ini_set ('default_socket_timeout', 5);	// 5 second limitation
			if (!$json = @file_get_contents ($queryUrl)) {
				#!# Report to admin?
				$this->html .= "\n<p class=\"warning\">Unfortunately, there was a problem retrieving the search results - apologies. Please try again later.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Convert to an array
			if (!$results = json_decode ($json, true)) {
				#!# Report to admin?
				$this->html .= "\n<p class=\"warning\">Unfortunately, there was a problem processing the search results - apologies. Please try again later.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Deal with pagination
			$first = $results['response']['resultPacket']['resultsSummary']['currStart'];
			$last = $results['response']['resultPacket']['resultsSummary']['currEnd'];
			$nextStart = $results['response']['resultPacket']['resultsSummary']['nextStart'];
			$total = $results['response']['resultPacket']['resultsSummary']['totalMatching'];
			
			# End if none
			if (!$total) {
				$this->html .= "\n<p>No items were found.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Define the navigation links
			#!# Refactor so that parameters are consistent and in same order as main search - there is duplicated code here
			$offsetPrevious = $first - 10;
			$navigation['previous'] = (($offsetPrevious >= 0) ? "<a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . ($offsetPrevious > 1 ? "&amp;offset={$offsetPrevious}" : '') . "\">" . '<img src="/images/general/previous.gif" alt="Previous" width="14" height="17" /></a>' : '&nbsp;');
			$navigation['current'] = "{$first}-{$last}";
			$navigation['next'] = (($nextStart < $total) ? "<a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . "&amp;offset={$nextStart}" . "\">" . '<img src="/images/general/next.gif" alt="Next" width="14" height="17" /></a>' : '&nbsp;');
			
			# Show the starting description and pagination
			$searchWords = explode ('+', $query);
			$this->html .= "\n\n<p>You searched for: <em>" . htmlspecialchars (urldecode (implode (' ', $searchWords))) . '</em>.</p>';
			
			# If there are no results (generally this happens at the high end, hence the 'about' added to the text above for total results, end here
			if (!$results['response']['resultPacket']['results']) {
				$this->html .= "\n<p>Sorry, no more results available for <a href=\"{$this->baseUrl}?{$queryTermField}=" . htmlspecialchars ($query) . '">' . htmlspecialchars ($query) . "</a>.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Show navigation controls
			$this->html .= "\n" . application::htmlUl ($navigation, 0, 'navigationmenu', false, false, false, $liClass = true);
			$this->html .= "\n<p>" . ($total <= 10 ? ($total == 1 ? 'There is one result' : "There are {$total} results") : "Showing results {$first}-{$last} of " . number_format ($total)) . ":</p>";
			
			# Create the list, looping through the results
			$this->html .= "\n<dl class=\"searchresults\">";
			foreach ($results['response']['resultPacket']['results'] as $result) {
				
				# Highlight search terms; this class is in pureContent
				$result['title'] = highlightSearchTerms::replaceHtml ($result['title'], $searchWords, 'referer', $sourceAsTextOnly = false, $showIndication = false);
				$result['summary'] = highlightSearchTerms::replaceHtml ($result['summary'], $searchWords, 'referer', $sourceAsTextOnly = true, $showIndication = false);
				
				# Format the filesize
				$fileSize = application::formatBytes ($result['fileSize']);
				
				# Show the results; HTML entities not required as the JSON already seems to have this encoded
				$this->html .= "
				<dt><a href=\"{$result['liveUrl']}\">" . $result['title'] . "</a></dt>
					<dd class=\"description\">" . $result['summary'] . "</dd>
					<dd class=\"attributes\"><a href=\"{$result['liveUrl']}\">{$result['liveUrl']}</a> - {$fileSize} <!-- {$result['date']} - Relevance: {$result['score']}--></dd>
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
