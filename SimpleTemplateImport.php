<?php

class SimpleTemplateImport extends ApiBase {
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
		if ( !isset( $this->json->templates ) || count( $this->json->templates ) <= 0 ) {
			$this->dieWithError( 'api-error-no-templates', '400' );
		}
		$params = $this->extractRequestParams();
		$comment = 'Automatically imported via SimpleImport extension';
		if ( isset(  $params[ 'comment' ] ) ) {
			$comment = $params[ 'comment' ];
		}
		foreach ( $this->json->templates as $template ) {
			$wikiText = htmlentities( $this->buildWikiText( $template ) );
			$bytes = mb_strlen($wikiText, 'utf8');
			if ( $bytes == 0 ) {
				$this->parseErrors[] = [
					"error" => wfMessage('apierror-invalid-template-data'),
					'template' => $template
				];
				continue;
			}
			$sha1 = sha1($wikiText);
			$dump = $this->fetchBaseDumpFile();
			$dump = str_replace( '{{TITLE}}', $template, $dump );
			$dump = str_replace( '{{NS}}', NS_TEMPLATE, $dump );
			$dump = str_replace( '{{TIMESTAMP}}', wfTimestamp( TS_ISO_8601, time() ), $dump );
			$dump = str_replace( '{{COMMENT}}', $comment, $dump );
			$dump = str_replace( '{{ID}}', '', $dump );
			$dump = str_replace( '{{USER_NAME}}', $this->getUser()->getName(), $dump );
			$dump = str_replace( '{{USER_ID}}', $this->getUser()->getId(), $dump );
			$dump = str_replace( '{{WIKI_TEXT}}', $wikiText, $dump );
			$dump = str_replace( '{{BYTES}}', $bytes, $dump );
			$dump = str_replace( '{{SHA1}}', $sha1, $dump );
			var_export($dump);
			$source = new ImportStringSource( $dump );
			$importer = new WikiImporter( $source, $this->getConfig() );
			$importer->setTargetNamespace( NS_TEMPLATE );
			try {
				$importer->doImport();
			} catch ( Exception $e ) {
				$this->dieWithException( $e, [ 'wrap' => 'apierror-import-unknownerror' ] );
			}
		}
		if ( count( $this->parseErrors ) > 0 ) {
			$this->getResult()->addValue( null, 'errors', $this->parseErrors );
		}
	}

	public function getAllowedParams() {
		return [
			'comment' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false,
			],
		];
	}

	private function buildWikiText( $template = '' ) {
		return file_get_contents( __DIR__ . '/dumpfiles/' . $template . '.txt' );
	}
}
