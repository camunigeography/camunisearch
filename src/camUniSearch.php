<?php

# PHP5 class to deal with interactions with the Cambridge University search engine - Ultraseek implementation
# Version 1.0.8
# http://download.geog.cam.ac.uk/projects/camunisearch/
# Licence: GPL
class camUniSearch
{
	/* NOTES:
	
	Note about encoding:
		This file uses the strategy that everything should be processed as UTF-8 (as that is what Ultraseek gives out)
		BUT that ONLY at the very final stage, things are converted to HTML entities for display
		www.phpwact.org/php/i18n/charsets is a good resource covering the issues
		
	XML retrieval:
		SimpleXML has been used rather than the DOM method. However it does not support document-orientated data, so the search highlighting is removed below.
		If refactoring this code to the DOM method, the following pages may be of particular interest:
			www.php.net/xml_parse_into_struct
			http://blog.phpdeveloper.org/?p=16
		
	Ultraseek query terms:
		The Ultraseek customisation guide is at http://www.ultraseek.com/support/docs/UltraseekCustom/wwhelp/wwhimpl/js/html/wwhelp.htm
		Note that qp cannot be used in XML retrieval
	*/
	
	private $charset = 'UTF-8';		# Encoding used in entity conversions; www.joelonsoftware.com/articles/Unicode.html is worth a read
	private $internalServer = 'int.web-search.cam.ac.uk';
	private $externalServer = 'ext.web-search.cam.ac.uk';
	
	# Class properties
	private $html = '';
	
	
	# Wrapper function to process XML search results
	function __construct ($searchServer = 'auto', $site = false, $div = 'searchform', $echoHtml = true, $queryTermField = 'qt')
	{
		# Load required libraries
		require_once ('application.php');
		require_once ('pureContent.php');	// Contains highlightSearchTerms
		require_once ('xml.php');
		
		# Define the base URL of this application
		$this->baseUrl = $_SERVER['SCRIPT_NAME'];
		
		# Default to the present site if none supplied
		if (!$site) {$site = $_SERVER['SERVER_NAME'];}
		
		# Allow posted variables
		$qt = (isSet ($_GET[$queryTermField]) ? $_GET[$queryTermField] : '');
		$st = (isSet ($_GET['st']) ? $_GET['st'] : '');
		
		# Show the form
		$this->html .= "\n<div id=\"{$div}\">";
		$this->html .= "\n\t" . '<form method="get" action="" name="f">';
		$this->html .= "\n\t\t" . '<input name="' . $queryTermField . '" type="text" value="' . ($qt ? htmlspecialchars ($qt) : 'Search') . "\" size=\"40\"  onfocus=\"if(this.value == 'Search'){this.value = '';}this.className='focused';\" onblur=\"if(this.value == ''){this.value = 'Search';this.className='blurred';}\" />";
		$this->html .= "\n\t\t" . '<input type="submit" value="Search" accesskey="s" />';
		$this->html .= "\n\t" . '</form>';
		$this->html .= "\n" . '</div>';
		
		# If a query term has been supplied, also show the results
		if ($qt) {
			
			# Decode the parameters
			$qt = urlencode ($qt);
			$st = urlencode ($st);
			
			# If set to auto, determine which search server to use, based on the user's DNS location
			if ($searchServer == 'auto') {
				$dns = gethostbyaddr ($_SERVER['REMOTE_ADDR']);
				$userIsInCam = (preg_match ('/\.cam\.ac\.uk$/', $dns));
				$searchServer = ($userIsInCam ? $this->internalServer : $this->externalServer);
			}
			
			# Define the location of the XML query result
			$queryUrl = "http://{$searchServer}/saquery.xml?qt=+site:{$site}+{$qt}" . ($st ? "&st={$st}" : '');
			
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
				
				$this->html .= "\n<p>No items were found." . ($suggestion ? " Did you perhaps mean <em><a href=\"{$this->baseUrl}?{$queryTermField}={$suggestion}\">{$suggestion}</a></em>?" : '') . '</p>';
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Define the navigation links
			$previous = $first - 10;
			$navigation['previous'] = (($previous > 0) ? "<a href=\"{$this->baseUrl}?{$queryTermField}={$qt}&amp;st={$previous}\">" . '<img src="/images/general/previous.gif" alt="Previous" width="14" height="17" /></a>' : '&nbsp;');
			$navigation['current'] = "{$first}-{$last}";
			$next = $first + 10;
			$navigation['next'] = (($next <= $total) ? "<a href=\"{$this->baseUrl}?{$queryTermField}={$qt}&amp;st={$next}\">" . '<img src="/images/general/next.gif" alt="Next" width="14" height="17" /></a>' : '&nbsp;');
			
			# Show the starting description and pagination
			$searchWords = explode ('+', $qt);
			$this->html .= "\n\n<p>You searched for: <em>" . htmlspecialchars (urldecode (implode (' ', $searchWords))) . '</em>.</p>';
			
			# If there are no results (generally this happens at the high end, hence the 'about' added to the text above for total results, end here
			if (!isSet ($results['results']['result'])) {
				$this->html .= "\n<p>Sorry, no more results available for <a href=\"{$this->baseUrl}?{$queryTermField}={$qt}\">" . htmlspecialchars ($qt) . "</a>.</p>";
				if ($echoHtml) {echo $this->html;}
				return;
			}
			
			# Show navigation controls
			$this->html .= "\n" . application::htmlUl ($navigation, 0, 'navigationmenu', false, false, false, $liClass = true);
			$this->html .= "\n<p>" . ($total <= 10 ? ($total == 1 ? 'There is one result' : "There are {$total} results") : "Showing results {$first}-{$last} of about {$total}") . ":</p>";
			
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
	
	
	# Getter for the HTML
	public function getHtml ()
	{
		return $this->html;
	}
}

?>