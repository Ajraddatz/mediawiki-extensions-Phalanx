<?php

class SpecialPhalanx extends SpecialPage {

	public $mDefaultExpire;

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		$this->mDefaultExpire = '1 year';
		parent::__construct( 'Phalanx', 'phalanx' /* restriction */ );
	}

	/**
	 * Show the special page
	 *
	 * @param $par Mixed: parameter passed to the special page or null
	 */
	public function execute( $par ) {
		wfProfileIn( __METHOD__ );
		global $wgOut, $wgUser;

		// Check restrictions
		if ( !$this->userCanExecute( $wgUser ) ) {
			$this->displayRestrictionError();
			return;
		}

		// Can't use the special page if database is locked...
		if ( wfReadOnly() ) {
			$wgOut->readOnlyPage();
			return;
		}

		// No access for blocked users
		if ( $wgUser->isBlocked() ) {
			$wgOut->blockedPage();
			return;
		}

		// Set the page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS & JS
		$wgOut->addModuleStyles( 'ext.phalanx' );
		$wgOut->addModuleScripts( 'ext.phalanx' );

		require_once( 'templates/phalanx.tmpl.php' );
		$template = new PhalanxTemplate();

		$pager = new PhalanxPager();

		$listing = $pager->getNavigationBar();
		$listing .= $pager->getBody();
		$listing .= $pager->getNavigationBar();

		$data = $this->prefillForm();

		$template->set( 'action', $this->getTitle()->getFullURL() );
		$template->set( 'default_expire', $this->mDefaultExpire );
		$template->set( 'expiries', Phalanx::getExpireValues() );
		$template->set( 'listing', $listing );
		$template->set( 'data', $data );
		$template->set( 'showEmail', $wgUser->isAllowed( 'phalanxemailblock' ) );

		$wgOut->addTemplate( $template );

		wfProfileOut( __METHOD__ );
	}

	function prefillForm() {
		global $wgRequest;

		$data = array();

		$id = $wgRequest->getInt( 'id' );
		if ( $id ) {
			$data = Phalanx::getFromId( $id );
			$data['type'] = Phalanx::getTypeNames( $data['type'] );
			$data['checkBlocker'] = '';
			$data['typeFilter'] = array();
		} else {
			$data['type'] = array_fill_keys( $wgRequest->getArray( 'type', array() ), true );
			$data['checkBlocker'] = $wgRequest->getText( 'wpPhalanxCheckBlocker', '' );
			$data['typeFilter'] = array_fill_keys( $wgRequest->getArray( 'wpPhalanxTypeFilter', array() ), true );
		}

		$data['checkId'] = ( $id ? $id : null );

		$data['text'] = $wgRequest->getText( 'ip' );
		$data['text'] = $wgRequest->getText( 'target', $data['text'] );
		$data['text'] = $wgRequest->getText( 'text', $data['text'] );

		$data['text'] = self::decodeValue( $data['text'] );

		$data['case'] = $wgRequest->getCheck( 'case' );
		$data['regex'] = $wgRequest->getCheck( 'regex' );
		$data['exact'] = $wgRequest->getCheck( 'exact' );

		$data['expire'] = $wgRequest->getText( 'expire', $this->mDefaultExpire );

		$data['lang'] = $wgRequest->getText( 'lang', 'all' );

		$data['reason'] = self::decodeValue( $wgRequest->getText( 'reason' ) );

		// test form input
		$data['test'] = self::decodeValue( $wgRequest->getText( 'test' ) );

		return $data;
	}

	static function decodeValue( $input ) {
		return htmlspecialchars( str_replace( '_', ' ', urldecode( $input ) ) );
	}

}

class PhalanxPager extends ReverseChronologicalPager {
	public function __construct() {
		global $wgRequest;

		parent::__construct();
		$this->mDb = wfGetDB( DB_SLAVE );

		$this->mSearchText = $wgRequest->getText( 'wpPhalanxCheckBlocker', null );
		$this->mSearchFilter = $wgRequest->getArray( 'wpPhalanxTypeFilter' );
		$this->mSearchId = $wgRequest->getInt( 'id' );
	}

	function getQueryInfo() {
		$query['tables'] = 'phalanx';
		$query['fields'] = '*';

		if ( $this->mSearchId ) {
			$query['conds'][] = "p_id = {$this->mSearchId}";
		} else {
			if ( !empty( $this->mSearchText ) ) {
				$query['conds'][] = '(p_text LIKE "%' . $this->mDb->escapeLike( $this->mSearchText ) . '%")';
			}

			if ( !empty( $this->mSearchFilter ) ) {
				$typemask = 0;
				foreach ( $this->mSearchFilter as $type ) {
					$typemask |= $type;
				}
				$query['conds'][] = "p_type & $typemask <> 0";
			}
		}

		return $query;
	}

	function getIndexField() {
		return 'p_timestamp';
	}

	function getStartBody() {
		return '<ul>';
	}

	function getEndBody() {
		return '</ul>';
	}

	function formatRow( $row ) {
		global $wgLang, $wgUser;

		// hide e-mail filters
		if ( $row->p_type & Phalanx::TYPE_EMAIL && !$wgUser->isAllowed( 'phalanxemailblock' ) ) {
			return '';
		}

		$author = User::newFromId( $row->p_author_id );
		$authorName = $author->getName();

		// uses escapeFullURL() for XHTML compliance (encoded ampersands)
		$phalanxUrl = SpecialPage::getTitleFor( 'Phalanx' )->escapeFullURL( array( 'id' => $row->p_id ) );
		$statsUrl = SpecialPage::getTitleFor( 'PhalanxStats', $row->p_id )->getFullURL();

		$html = '<li id="phalanx-block-' . $row->p_id . '">';

		$html .= '<b>' . htmlspecialchars( $row->p_text ) . '</b> (' ;

		$list = array(
			( $row->p_regex ? wfMsg( 'phalanx-list-regex' ) : wfMsg( 'phalanx-plain-text' ) )
		);
		if( $row->p_case ) {
			$list[] = wfMsg( 'phalanx-format-case' );
		}
		if( $row->p_exact ) {
			$list[] = wfMsg( 'phalanx-format-exact' );
		}
		$html .= $wgLang->commaList( $list );

		$html .= ') ';

		// control links
		$html .= " &bull; <a class=\"unblock\" href=\"{$phalanxUrl}\">" . wfMsg( 'phalanx-link-unblock' ) . '</a>';
		$html .= " &bull; <a class=\"modify\" href=\"{$phalanxUrl}\">" . wfMsg( 'phalanx-link-modify' ) . '</a>';
		$html .= " &bull; <a class=\"stats\" href=\"{$statsUrl}\">" . wfMsg( 'phalanx-link-stats' ) . '</a>';

		// types
		$html .= '<br /> ' . wfMsg( 'phalanx-display-row-blocks', implode( ', ', Phalanx::getTypeNames( $row->p_type ) ) );

		$html .= ' &bull; ' . wfMsgExt(
			'phalanx-display-row-created',
			'parseinline',
			$authorName,
			$wgLang->timeanddate( $row->p_timestamp )
		);

		$html .= '</li>';

		return $html;
	}
}
