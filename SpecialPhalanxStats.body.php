<?php

class PhalanxStats extends UnlistedSpecialPage {

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'PhalanxStats', 'phalanx' );
	}

	/**
	 * Show the special page
	 *
	 * @param $par Mixed: parameter passed to the special page or null
	 */
	public function execute( $par ) {
		global $wgOut, $wgUser, $wgLang, $wgRequest;

		// Check restrictions
		if ( !$this->userCanExecute( $wgUser ) ) {
			$this->displayRestrictionError();
			return;
		}

		// No access for blocked users
		if ( $wgUser->isBlocked() ) {
			$wgOut->blockedPage();
			return;
		}

		if ( empty( $par ) ) {
			$par = $wgRequest->getInt( 'blockId' );
		}

		// Set the page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS
		$wgOut->addModuleStyles( 'ext.phalanx' );

		$wikiId = $wgRequest->getInt( 'wikiId' );

		// show block ID or blocks for wiki
		if ( strpos( $par, 'wiki' ) !== false ) {
			list( , $par ) = explode( '/', $par );
			$show = 'blockWiki';
		} elseif ( $wikiId ) { // GET requests via the form
			$show = 'blockWiki';
			$par = $wikiId;
		} else { // fallback
			$show = 'blockId';
		}

		// give up if no block ID is given
		if ( empty( $par ) ) {
			$this->showForms();
			return true;
		}

		if ( $show == 'blockId' ) {
			$this->blockStats( $par );
		} else {
			$this->blockWiki( $par );
		}
	}

	private function blockStats( $par ) {
		global $wgOut, $wgLang, $wgUser, $wgRequest;

		// We have a valid ID, change the title to use it
		$wgOut->setPageTitle( wfMsg( 'phalanxstats' ) . ' #' . $par );

		$block = array();
		$block = Phalanx::getFromId( intval( $par ) );

		if ( empty( $block ) ) {
			$wgOut->addWikiMsg( 'phalanx-stats-block-notfound', $par );
			return true;
		}

		// process block data for display
		$data = array();
		$data['id'] = $block['id'];
		$data['author_id'] = User::newFromId( $block['author_id'] )->getName();
		$data['type'] = implode( ', ', Phalanx::getTypeNames( $block['type'] ) );

		$data['timestamp'] = $wgLang->timeanddate( $block['timestamp'] );
		if ( $block['expire'] == null ) {
			$data['expire'] = wfMsg( 'infiniteblock' );
		} else {
			$data['expire'] = $wgLang->timeanddate( $block['expire'] );
		}
		$data['regex'] = $block['regex'] ? wfMsg( 'phalanx-yes' ) : wfMsg( 'phalanx-no' );
		$data['case'] = $block['case'] ? wfMsg( 'phalanx-yes' ) : wfMsg( 'phalanx-no' );
		$data['exact'] = $block['exact'] ? wfMsg( 'phalanx-yes' ) : wfMsg( 'phalanx-no' );
		$data['lang'] = empty( $block['lang'] ) ? '*' : $block['lang'];

		// Pull these out of the array, so they don't get used in the top rows
		if ( $block['type'] & Phalanx::TYPE_EMAIL && !$wgUser->isAllowed( 'phalanxemailblock' ) ) {
			// hide email from non-privildged users
			$data2['text'] = wfMsg( 'phalanx-email-filter-hidden' );
		} else {
			$data2['text'] = $block['text'];
		}
		$data2['reason'] = $block['reason'];

		$headers = array(
			wfMsg( 'phalanx-stats-table-id' ),
			wfMsg( 'phalanx-stats-table-user' ),
			wfMsg( 'phalanx-stats-table-type' ),
			wfMsg( 'phalanx-stats-table-create' ),
			wfMsg( 'phalanx-stats-table-expire' ),
			wfMsg( 'phalanx-stats-table-regex' ),
			wfMsg( 'phalanx-stats-table-case' ),
			wfMsg( 'phalanx-stats-table-exact' ),
			wfMsg( 'phalanx-stats-table-language' ),
		);

		$html = '';

		$tableAttribs = array(
			'border' => 1,
			'class' => 'wikitable',
			'style' => 'font-family: monospace;',
		);

		// Use magic to build it
		$table = Xml::buildTable( array( $data ), $tableAttribs, $headers );
		// Rip off bottom
		$table = str_replace( '</table>', '', $table );
		// Add some stuff
		$table .= '<tr><th>' . wfMsg( 'phalanx-stats-table-text' ) .
			'</th><td colspan="8">' . htmlspecialchars( $data2['text'] ) .
			'</td></tr>';
		$table .= '<tr><th>' . wfMsg( 'phalanx-stats-table-reason' ) .
			"</th><td colspan=\"8\">{$data2['reason']}</td></tr>";
		// Seal it back up
		$table .= '</table>';

		$html .= $table . "\n";

		$phalanxURL = SpecialPage::getTitleFor( 'Phalanx' )->getFullURL( array( 'id' => $block['id'] ) );
		$html .= " &bull; <a class=\"modify\" href=\"{$phalanxURL}\">" .
			wfMsg( 'phalanx-link-modify' ) . "</a><br />\n";
		$html .= "<br />\n";
		$wgOut->addHTML( $html );

		$pager = new PhalanxStatsPager( $par );

		$html = '';
		$html .= $pager->getNavigationBar();
		$html .= $pager->getBody();
		$html .= $pager->getNavigationBar();

		$wgOut->addHTML( $html );
	}

	private function blockWiki( $par ) {
		global $wgOut, $wgLang, $wgUser, $wgRequest;

		if ( !is_numeric( $par ) ) {
			return false;
		}
		$url = self::getFSD( $par );
		$sitename = self::getSitename( $par );

		// We have a valid ID, change the title to use it
		$wgOut->setPageTitle( wfMsg( 'phalanxstats' ) . ': ' . $url );

		// process block data for display
		$data['wiki_id'] = $par;
		$data['sitename'] = $sitename;
		$data['url'] = $url;
		//$data['last_timestamp'] = $wgLang->timeanddate( $oWiki->city_last_timestamp );

		$html = '';

		$headers = array(
			wfMsg( 'phalanx-stats-table-wiki-id' ),
			wfMsg( 'phalanx-stats-table-wiki-name' ),
			wfMsg( 'phalanx-stats-table-wiki-url' ),
			//wfMsg( 'phalanx-stats-table-wiki-last-edited' ),
		);

		$tableAttribs = array(
			'border' => 1,
			'class' => 'wikitable',
			'style' => 'font-family: monospace;',
		);

		// Use magic to build it
		$table = Xml::buildTable( array( $data ), $tableAttribs, $headers );
		$html .= $table . "<br />\n";

		$wgOut->addHTML( $html );

		$pager = new PhalanxWikiStatsPager( $par );

		$html = '';
		$html .= $pager->getNavigationBar();
		$html .= $pager->getBody();
		$html .= $pager->getNavigationBar();

		$wgOut->addHTML( $html );
	}

	/**
	 * Fetch the full subdomain for the wiki with ID number $wikiID
	 *
	 * @param $wikiID Integer: wiki ID number
	 * @return array
	 */
	private static function getFSD( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		return $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiFullSubdomain',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);
	}

	/**
	 * Fetch the sitename for the wiki with ID number $wikiID
	 *
	 * @param $wikiID Integer: wiki ID number
	 * @return array
	 */
	private static function getSitename( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		return $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgSitename',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);
	}

	/**
	 * Show "Recent triggers of a block" and "Recent blocks on a wiki" forms
	 * if no parameters were passed
	 */
	private function showForms() {
		global $wgLang, $wgOut;

		$statsURL = SpecialPage::getTitleFor( 'PhalanxStats' )->getFullURL();

		$formParam = array( 'method' => 'get', 'action' => $statsURL );

		$content = '';
		$content .= Xml::openElement( 'form', $formParam ) . "\n";
		$content .= wfMsg( 'phalanx-stats-id',
			Xml::input( 'blockId', 5, '', array() )
		);
		$content .= Xml::submitButton( wfMsg( 'phalanx-stats-load-btn' ) ) . "\n";
		$content .= Xml::closeElement( 'form' ) . "\n";
		$content .= wfMsg( 'phalanx-stats-example' ) . "\n<ul>\n";
		$content .= '<li>' . SpecialPage::getTitleFor( 'PhalanxStats', '123456' )->getFullURL() . "</li>\n";
		$content .= '<li>' . SpecialPage::getTitleFor( 'PhalanxStats' )->getFullURL( array( 'blockId' => '123456' ) ) . "</li>\n";
		$content .= "</ul>\n";

		$wgOut->addHTML( Xml::fieldset( wfMsg( 'phalanx-stats-recent-triggers' ), $content, array() ) );

		$formParam = array( 'method' => 'get', 'action' => $statsURL );

		$content = '';
		$content .= Xml::openElement( 'form', $formParam ) . "\n";
		$content .= wfMsg( 'phalanx-stats-id', Xml::input( 'wikiId', 5, '', array() ) );
		$content .= Xml::submitButton( wfMsg( 'phalanx-stats-load-btn' ) ) . "\n";
		$content .= Xml::closeElement( 'form' ) . "\n";
		$content .= wfMsg( 'phalanx-stats-example' ) . "\n<ul>\n";
		$content .= '<li>' . SpecialPage::getTitleFor( 'PhalanxStats', 'wiki/123456' )->getFullURL() . "</li>\n";
		$content .= "</ul>\n";

		$wgOut->addHTML( Xml::fieldset( wfMsg( 'phalanx-stats-recent-blocks' ), $content, array() ) );

		return;
	}
}

class PhalanxStatsPager extends ReverseChronologicalPager {
	public function __construct( $id ) {
		parent::__construct();
		$this->mDb = wfGetDB( DB_SLAVE );

		$this->mBlockId = (int) $id;
		$this->mDefaultQuery['blockId'] = (int) $id;
	}

	function getQueryInfo() {
		$query['tables'] = 'phalanx_stats';
		$query['fields'] = '*';
		$query['conds'] = array(
			'ps_blocker_id' => $this->mBlockId,
		);

		return $query;
	}

	function getPagingQueries() {
		$queries = parent::getPagingQueries();

		foreach ( $queries as $type => $query ) {
			$query[$type]['blockId'] = $this->mBlockId;
			$queries[$type] = $query;
		}

		return $queries;
	}

	function getIndexField() {
		return 'ps_timestamp';
	}

	function getStartBody() {
		return '<ul id="phalanx-block-' . $this->mBlockId . '-stats">';
	}

	function getEndBody() {
		return '</ul>';
	}

	function formatRow( $row ) {
		global $wgLang;

		$type = implode( Phalanx::getTypeNames( $row->ps_blocker_type ) );
		$username = $row->ps_blocked_user;
		$timestamp = $wgLang->timeanddate( $row->ps_timestamp );
		$wikiNumber = $row->ps_wiki_id;
		$url = self::getFSD( $wikiNumber );

		$html = '<li>' . wfMsg(
			'phalanx-stats-entry',
			$type, $username, $url, $timestamp
		) . '</li>';

		return $html;
	}

	/**
	 * Fetch the full subdomain for the wiki with ID number $wikiID
	 *
	 * @param $wikiID Integer: wiki ID number
	 * @return array
	 */
	private static function getFSD( $wikiID ) {
		$dbr = wfGetDB( DB_SLAVE );

		return $dbr->selectField(
			'wiki_settings',
			'ws_value',
			array(
				'ws_setting' => 'wgWikiFullSubdomain',
				'ws_wiki' => $wikiID
			),
			__METHOD__
		);
	}
}

class PhalanxWikiStatsPager extends ReverseChronologicalPager {
	public function __construct( $id ) {
		parent::__construct();
		$this->mDb = wfGetDB( DB_SLAVE );

		$this->mWikiId = (int) $id;
		$this->mTitle = SpecialPage::getTitleFor( 'Phalanx' );
		$this->mTitleStats = SpecialPage::getTitleFor( 'PhalanxStats' );
	}

	function getQueryInfo() {
		$query['tables'] = 'phalanx_stats';
		$query['fields'] = '*';
		$query['conds'] = array(
			'ps_wiki_id' => $this->mWikiId,
		);

		return $query;
	}

	function getPagingQueries() {
		$queries = parent::getPagingQueries();

		foreach ( $queries as $type => $query ) {
			if ( $query === false ) {
				continue;
			}
			$query['wikiId'] = $this->mWikiId;
			$queries[$type] = $query;
		}

		return $queries;
	}

	function getIndexField() {
		return 'ps_timestamp';
	}

	function getStartBody() {
		return '<ul id="phalanx-block-wiki-' . $this->mWikiId . '-stats">';
	}

	function getEndBody() {
		return '</ul>';
	}

	function formatRow( $row ) {
		global $wgLang;

		$type = implode( Phalanx::getTypeNames( $row->ps_blocker_type ) );

		$username = $row->ps_blocked_user;

		$timestamp = $wgLang->timeanddate( $row->ps_timestamp );

		$blockId = (int) $row->ps_blocker_id;

		// block
		$phalanxURL = Linker::link(
			$this->mTitle,
			$blockId,
			array(),
			array( 'id' => $blockId )
		);

		// stats
		$statsURL = Linker::link(
			$this->mTitleStats,
			wfMsg( 'phalanx-link-stats' ),
			array(),
			array( 'blockId' => $blockId )
		);

		$html = '<li>';
		$html .= wfMsgExt(
			'phalanx-stats-row-per-wiki',
			array( 'parseinline', 'replaceafter' ),
			$type,
			$username,
			$phalanxURL,
			$timestamp,
			$statsURL
		);
		$html .= '</li>';

		return $html;
	}
}