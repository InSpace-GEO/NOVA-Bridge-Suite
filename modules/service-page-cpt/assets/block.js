( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { InspectorControls, MediaUpload } = wp.blockEditor || wp.editor;
	const { PanelBody, TextControl, TextareaControl, Button, Notice } = wp.components;
	const { Fragment } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { __, sprintf } = wp.i18n;
	const blockSettings = window.serviceCptBlock || {};
	const componentVisibility = blockSettings.components || {};
	const showHeroSection = componentVisibility.hero !== false;
	const showIntroSection = componentVisibility.intro !== false;
	const showContentSection = componentVisibility.content !== false;
	const showTableSection = componentVisibility.table !== false;
	const showImagesSection = componentVisibility.images !== false;
	const showSidebarCtaSection = componentVisibility.sidebarCta !== false;
	const showWideCtaSection = componentVisibility.wideCta !== false;
	const showFaqSection = componentVisibility.faq !== false;
	const showHeroCtaFields = blockSettings.showHeroCtaFields !== false;
	const showSidebarCtaFields = blockSettings.showSidebarCtaFields !== false;
	const showWideCtaFields = blockSettings.showWideCtaFields !== false;
	const showGlobalCtaNotice =
		( showHeroSection && ! showHeroCtaFields )
		|| ( showSidebarCtaSection && ! showSidebarCtaFields )
		|| ( showWideCtaSection && ! showWideCtaFields );

	const bulletSlots = [ 0, 1, 2 ];
	const faqSlots = [ 0, 1, 2, 3 ];
	const formatTable = ( table ) => {
		if ( ! Array.isArray( table ) ) {
			return '';
		}

		return table
			.map( ( row ) => ( Array.isArray( row ) ? row.join( ' | ' ) : '' ) )
			.join( '\n' );
	};

	const parseTable = ( value ) => {
		if ( ! value ) {
			return [];
		}

		return value
			.split( /\r?\n/ )
			.map( ( row ) => row.trim() )
			.filter( ( row ) => row.length )
			.map( ( row ) =>
				row.split( '|' ).map( ( cell ) => cell.trim() )
			);
	};

	const mediaPicker = ( label, value, onChange ) => (
		<MediaUpload
			onSelect={ ( media ) => onChange( media && media.id ? media.id : 0 ) }
			allowedTypes={ [ 'image' ] }
			value={ value }
			render={ ( { open } ) => (
				<Button variant="secondary" onClick={ open }>
					{ value ? `${ label }: ID ${ value }` : `${ label }` }
				</Button>
			) }
		/>
	);

	registerBlockType( 'service-cpt/layout', {
		title: __( 'Service Page Layout', 'service-cpt' ),
		description: __( 'Locked layout for service pages with structured fields in the sidebar.', 'service-cpt' ),
		icon: 'layout',
		category: 'layout',
		supports: { html: false, inserter: false },
		edit() {
			const postType = useSelect( ( select ) => {
				const getter = select( 'core/editor' ).getCurrentPostType;
				return getter ? getter() : '';
			}, [] );
			const meta = useSelect(
				( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
				[]
			);
			const { editPost } = useDispatch( 'core/editor' );
			const safeFaqValue = ( index, field ) => {
				const items = Array.isArray( meta.sp_faq ) ? meta.sp_faq : [];
				const entry = items[ index ];

				if ( entry && typeof entry === 'object' ) {
					return entry[ field ] || '';
				}

				return '';
			};

			const updateMeta = ( key, value ) => {
				editPost( { meta: { ...meta, [ key ]: value } } );
			};

			const updateBullet = ( index, value ) => {
				const next = Array.isArray( meta.sp_cta_bullets ) ? [ ...meta.sp_cta_bullets ] : [];
				next[ index ] = value;
				updateMeta( 'sp_cta_bullets', next );
			};

			const updateFaq = ( index, field, value ) => {
				const next = Array.isArray( meta.sp_faq )
					? meta.sp_faq.map( ( item ) => ( { ...item } ) )
					: [];

				next[ index ] = {
					...( next[ index ] || { question: '', answer: '' } ),
					[ field ]: value,
				};

				updateMeta( 'sp_faq', next );
			};

			if ( postType && postType !== 'service_page' ) {
				return (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'This block is only used inside Service Page posts.', 'service-cpt' ) }
					</Notice>
				);
			}

			const showContentPanel =
				showIntroSection
				|| showContentSection
				|| showTableSection
				|| showImagesSection;

			return (
				<Fragment>
					<InspectorControls>
						{ showGlobalCtaNotice && (
							<Notice status="info" isDismissible={ false }>
								{ __( 'Global CTAs are set in Settings → Service Pages. Per-page CTA fields are hidden.', 'service-cpt' ) }
							</Notice>
						) }

						{ showHeroSection && (
							<PanelBody title={ __( 'Hero', 'service-cpt' ) } initialOpen>
								<TextControl
									label={ __( 'Eyebrow', 'service-cpt' ) }
									value={ meta.sp_hero_eyebrow || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_eyebrow', value ) }
								/>
								<TextControl
									label={ __( 'Title (H1)', 'service-cpt' ) }
									value={ meta.sp_hero_title || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_title', value ) }
								/>
								<TextareaControl
									label={ __( 'Lead copy', 'service-cpt' ) }
									value={ meta.sp_hero_copy || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_copy', value ) }
								/>
								{ showHeroCtaFields && (
									<Fragment>
										<TextControl
											label={ __( 'Primary CTA label', 'service-cpt' ) }
											value={ meta.sp_hero_primary_label || '' }
											onChange={ ( value ) => updateMeta( 'sp_hero_primary_label', value ) }
										/>
										<TextControl
											label={ __( 'Primary CTA URL', 'service-cpt' ) }
											value={ meta.sp_hero_primary_url || '' }
											onChange={ ( value ) => updateMeta( 'sp_hero_primary_url', value ) }
										/>
										<TextControl
											label={ __( 'Secondary CTA label', 'service-cpt' ) }
											value={ meta.sp_hero_secondary_label || '' }
											onChange={ ( value ) => updateMeta( 'sp_hero_secondary_label', value ) }
										/>
										<TextControl
											label={ __( 'Secondary CTA URL', 'service-cpt' ) }
											value={ meta.sp_hero_secondary_url || '' }
											onChange={ ( value ) => updateMeta( 'sp_hero_secondary_url', value ) }
										/>
									</Fragment>
								) }
							</PanelBody>
						) }

						{ showContentPanel && (
							<PanelBody title={ __( 'Intro & content', 'service-cpt' ) } initialOpen={ false }>
								{ showIntroSection && (
									<TextareaControl
										label={ __( 'Intro paragraph', 'service-cpt' ) }
										value={ meta.sp_intro || '' }
										onChange={ ( value ) => updateMeta( 'sp_intro', value ) }
									/>
								) }
								{ showContentSection && (
									<Fragment>
										<TextareaControl
											label={ __( 'Main text 1', 'service-cpt' ) }
											value={ meta.sp_main_1 || '' }
											onChange={ ( value ) => updateMeta( 'sp_main_1', value ) }
										/>
										<TextareaControl
											label={ __( 'Main text 2', 'service-cpt' ) }
											value={ meta.sp_main_2 || '' }
											onChange={ ( value ) => updateMeta( 'sp_main_2', value ) }
										/>
										<TextareaControl
											label={ __( 'Main text 3', 'service-cpt' ) }
											value={ meta.sp_main_3 || '' }
											onChange={ ( value ) => updateMeta( 'sp_main_3', value ) }
										/>
									</Fragment>
								) }
								{ showTableSection && (
									<TextareaControl
										label={ __( 'Table rows (use | between columns)', 'service-cpt' ) }
										value={ formatTable( meta.sp_table ) }
										onChange={ ( value ) => updateMeta( 'sp_table', parseTable( value ) ) }
									/>
								) }
								{ showImagesSection && (
									<Fragment>
										<div style={ { marginTop: '12px' } }>
											{ mediaPicker(
												__( 'Image 1', 'service-cpt' ),
												meta.sp_image_1,
												( value ) => updateMeta( 'sp_image_1', parseInt( value, 10 ) || 0 )
											) }
										</div>
										<div style={ { marginTop: '8px' } }>
											{ mediaPicker(
												__( 'Image 2', 'service-cpt' ),
												meta.sp_image_2,
												( value ) => updateMeta( 'sp_image_2', parseInt( value, 10 ) || 0 )
											) }
										</div>
									</Fragment>
								) }
							</PanelBody>
						) }

						{ showSidebarCtaSection && showSidebarCtaFields && (
							<PanelBody title={ __( 'Sidebar CTA', 'service-cpt' ) } initialOpen={ false }>
								<TextControl
									label={ __( 'Title (H3)', 'service-cpt' ) }
									value={ meta.sp_sidebar_title || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_title', value ) }
								/>
								<TextareaControl
									label={ __( 'Copy', 'service-cpt' ) }
									value={ meta.sp_sidebar_copy || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_copy', value ) }
								/>
								<TextControl
									label={ __( 'Primary label', 'service-cpt' ) }
									value={ meta.sp_sidebar_primary_label || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_primary_label', value ) }
								/>
								<TextControl
									label={ __( 'Primary URL', 'service-cpt' ) }
									value={ meta.sp_sidebar_primary_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_primary_url', value ) }
								/>
								<TextControl
									label={ __( 'Secondary label', 'service-cpt' ) }
									value={ meta.sp_sidebar_secondary_label || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_secondary_label', value ) }
								/>
								<TextControl
									label={ __( 'Secondary URL', 'service-cpt' ) }
									value={ meta.sp_sidebar_secondary_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_sidebar_secondary_url', value ) }
								/>
							</PanelBody>
						) }

						{ showWideCtaSection && showWideCtaFields && (
							<PanelBody title={ __( 'Wide CTA band', 'service-cpt' ) } initialOpen={ false }>
								<TextControl
									label={ __( 'Title (H2)', 'service-cpt' ) }
									value={ meta.sp_cta_title || '' }
									onChange={ ( value ) => updateMeta( 'sp_cta_title', value ) }
								/>
								{ bulletSlots.map( ( index ) => (
									<TextControl
										key={ index }
										label={ sprintf( __( 'Bullet %d', 'service-cpt' ), index + 1 ) }
										value={ ( meta.sp_cta_bullets || [] )[ index ] || '' }
										onChange={ ( value ) => updateBullet( index, value ) }
									/>
								) ) }
								<TextControl
									label={ __( 'Button label', 'service-cpt' ) }
									value={ meta.sp_cta_button_label || '' }
									onChange={ ( value ) => updateMeta( 'sp_cta_button_label', value ) }
								/>
								<TextControl
									label={ __( 'Button URL', 'service-cpt' ) }
									value={ meta.sp_cta_button_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_cta_button_url', value ) }
								/>
								<TextControl
									label={ __( 'More info label', 'service-cpt' ) }
									value={ meta.sp_cta_more_text || '' }
									onChange={ ( value ) => updateMeta( 'sp_cta_more_text', value ) }
								/>
								<TextControl
									label={ __( 'More info URL', 'service-cpt' ) }
									value={ meta.sp_cta_more_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_cta_more_url', value ) }
								/>
							</PanelBody>
						) }

						{ showFaqSection && (
							<PanelBody title={ __( 'FAQ', 'service-cpt' ) } initialOpen={ false }>
								{ faqSlots.map( ( index ) => (
									<Fragment key={ index }>
										<TextControl
											label={ sprintf( __( 'Question %d', 'service-cpt' ), index + 1 ) }
											value={ safeFaqValue( index, 'question' ) }
											onChange={ ( value ) => updateFaq( index, 'question', value ) }
										/>
										<TextareaControl
											label={ sprintf( __( 'Answer %d', 'service-cpt' ), index + 1 ) }
											value={ safeFaqValue( index, 'answer' ) }
											onChange={ ( value ) => updateFaq( index, 'answer', value ) }
										/>
									</Fragment>
								) ) }
							</PanelBody>
						) }
					</InspectorControls>

					<div className="service-cpt-block">
						<h2 className="service-cpt-block__title">{ __( 'Service Page Layout', 'service-cpt' ) }</h2>
						<p className="service-cpt-block__hint">
							{ __( 'Use the sidebar panels to fill in the content fields. The layout and styling are locked for consistency.', 'service-cpt' ) }
						</p>
					</div>
				</Fragment>
			);
		},
		save() {
			return null;
		},
	} );
}( window.wp ) );
