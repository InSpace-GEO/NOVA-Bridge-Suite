( function ( wp, settings ) {
	if ( ! wp || ! settings ) {
		return;
	}

	const { select, dispatch, subscribe } = wp.data;
	const { parse, serialize } = wp.blocks;
	let appliedTemplate = null;
	let appliedSignature = null;
	let templateSignatures = null;

	const isEmptyPost = ( blocks ) => {
		if ( ! blocks || ! blocks.length ) {
			return true;
		}

		if ( blocks.length !== 1 ) {
			return false;
		}

		const block = blocks[ 0 ];
		if ( block.name !== 'core/paragraph' ) {
			return false;
		}

		const content = block.attributes && block.attributes.content ? block.attributes.content : '';
		return '' === content;
	};

	const getSelectedTemplate = () => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		if ( meta.sp_template ) {
			return meta.sp_template;
		}

		return settings.defaultTemplate;
	};

	const buildTemplateSignatures = () => {
		if ( templateSignatures ) {
			return;
		}

		templateSignatures = {};

		if ( ! settings.templates ) {
			return;
		}

		Object.keys( settings.templates ).forEach( ( slug ) => {
			const template = settings.templates[ slug ];
			if ( ! template || ! template.content ) {
				return;
			}

			const parsed = parse( template.content );
			if ( parsed && parsed.length ) {
				templateSignatures[ slug ] = serialize( parsed );
			}
		} );
	};

	const getCurrentSignature = () => {
		const blocks = select( 'core/block-editor' ).getBlocks();
		return serialize( blocks );
	};

	const maybeApplyTemplate = () => {
		const postType = select( 'core/editor' ).getCurrentPostType();
		if ( postType !== settings.postType ) {
			return;
		}

		buildTemplateSignatures();

		const blocks = select( 'core/block-editor' ).getBlocks();
		const currentSignature = serialize( blocks );
		if ( null === appliedTemplate && templateSignatures ) {
			const matched = Object.keys( templateSignatures ).find(
				( slug ) => templateSignatures[ slug ] === currentSignature
			);

			if ( matched ) {
				appliedTemplate = matched;
				appliedSignature = templateSignatures[ matched ];
			}
		}

		const selectedTemplate = getSelectedTemplate();
		if ( ! selectedTemplate ) {
			return;
		}

		const canReplace =
			isEmptyPost( blocks ) ||
			( appliedSignature && appliedSignature === currentSignature );

		if ( ! canReplace || appliedTemplate === selectedTemplate ) {
			return;
		}

		const template = settings.templates && settings.templates[ selectedTemplate ]
			? settings.templates[ selectedTemplate ]
			: null;

		if ( ! template || ! template.content ) {
			appliedTemplate = selectedTemplate;
			return;
		}

		const parsed = parse( template.content );
		if ( parsed && parsed.length ) {
			appliedTemplate = selectedTemplate;
			appliedSignature = serialize( parsed );
			dispatch( 'core/block-editor' ).resetBlocks( parsed );
		}
	};

	subscribe( () => {
		maybeApplyTemplate();
	} );
}( window.wp, window.serviceCptTemplate ) );
