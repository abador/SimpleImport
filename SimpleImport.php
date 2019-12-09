<?php

class SimpleImport extends ApiBase {
	protected $json = null;
	protected $parseErrors = [];
	protected $baseDump = null;

	protected function fetchBaseDumpFile() {
		if ( is_null( $this->baseDump ) ) {
			$this->baseDump = file_get_contents( __DIR__ . '/dumpfiles/baseDump.xml' );
		}
		return $this->baseDump;
	}

	public function execute() {
		$data = $this->getRequest()->getRawInput();
		$this->json = json_decode( $data );
		if ( !$this->json ) {
			$this->dieWithError( 'apierror-invalid-json', '400' );
		}
		$params = $this->extractRequestParams();
		$comment = 'Automatically imported via SimpleImport extension';
		if ( isset(  $params[ 'comment' ] ) ) {
			$comment = $params[ 'comment' ];
		}
		$pageId = "";
		if ( isset(  $params[ 'page_id' ] ) ) {
			$pageId = "<id>{$params[ 'page_id' ]}</id>";
		}
		$wikiText = htmlentities( $this->buildWikiText() );
		$bytes = mb_strlen($wikiText, 'utf8');
		if ( $bytes == 0 ) {
			$this->dieWithError( 'apierror-invalid-page-data', '400' );
		}
		$sha1 = sha1($wikiText);
		$dump = $this->fetchBaseDumpFile();
		$dump = str_replace( '{{TITLE}}', $params['page_title'], $dump );
		$dump = str_replace( '{{NS}}', $params['page_ns'], $dump );
		$dump = str_replace( '{{TIMESTAMP}}', wfTimestamp( TS_ISO_8601, time() ), $dump );
		$dump = str_replace( '{{COMMENT}}', $comment, $dump );
		$dump = str_replace( '{{ID}}', $pageId, $dump );
		$dump = str_replace( '{{USER_NAME}}', $this->getUser()->getName(), $dump );
		$dump = str_replace( '{{USER_ID}}', $this->getUser()->getId(), $dump );
		$dump = str_replace( '{{WIKI_TEXT}}', $wikiText, $dump );
		$dump = str_replace( '{{BYTES}}', $bytes, $dump );
		$dump = str_replace( '{{SHA1}}', $sha1, $dump );
		$source = new ImportStringSource( $dump );
		$importer = new WikiImporter( $source, $this->getConfig() );
		$importer->setTargetNamespace( $params['page_ns'] );
		try {
			$importer->doImport();
		} catch ( Exception $e ) {
			$this->dieWithException( $e, [ 'wrap' => 'apierror-import-unknownerror' ] );
		}
		if ( count( $this->parseErrors ) > 0 ) {
			$this->getResult()->addValue( null, 'errors', $this->parseErrors );
		}
	}

	public function getAllowedParams() {
		return [
			'page_title' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
			'page_ns' => [
				ApiBase::PARAM_TYPE => 'namespace',
				ApiBase::PARAM_REQUIRED => true,
			],
			'page_id' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false,
			],
			'comment' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false,
			],
		];
	}

	private function buildWikiText() {
		$wikiText = "";
		if ( isset( $this->json->page ) && count( $this->json->page ) > 0 ) {
			foreach ( $this->json->page as $page ) {
				$title = Title::newFromText( $page->template, NS_TEMPLATE );
				if ( $title->getArticleID() == 0 ) {
					$this->parseErrors[] = [
						"error" => wfMessage('api-error-no-template'),
						'page' =>$page->template
					];
					continue;
				}
				$params = '';
				array_walk(
					$page->data,
					function ($item, $key) use (&$params) {
						$params .= "\n|" . $key ."=" . $item;
					}
				);
				$wikiText .= "{{{$page->template}{$params}}}";
			}
		}
		return $wikiText;
	}
}
