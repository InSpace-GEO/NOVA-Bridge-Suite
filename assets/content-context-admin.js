( function () {
	'use strict';

	var APP_ID = 'nova-content-context-app';
	var PAYLOAD_ID = 'nova-content-context-payload';
	var SHOW_IN_REST_WARNING = 'This content type is not usable through the API because show_in_rest is disabled.';
	var FIELD_GROUPS = [
		{ id: 'core', label: 'Core content', description: 'WordPress content and publishing fields.' },
		{ id: 'media_taxonomy', label: 'Media & taxonomies', description: 'Images, featured media, categories, tags and other term relationships.' },
		{ id: 'acf_custom', label: 'ACF & custom fields', description: 'ACF fields and relevant registered metadata.' },
		{ id: 'seo', label: 'SEO', description: 'Fields exposed by the active SEO integration.' },
		{ id: 'builder', label: 'Page-builder bridge', description: 'Actual text fields returned by a NOVA page-builder bridge.' },
		{ id: 'other', label: 'Other', description: 'Other relevant fields accepted by this publishing endpoint.' }
	];
	var uid = 0;

	function onReady( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
			return;
		}
		callback();
	}

	function isObject( value ) {
		return value !== null && typeof value === 'object' && ! Array.isArray( value );
	}

	function hasOwn( object, key ) {
		return Object.prototype.hasOwnProperty.call( object, key );
	}

	function stringValue( value, fallback ) {
		if ( typeof value === 'string' || typeof value === 'number' ) {
			return String( value );
		}
		return fallback || '';
	}

	function firstString( object, keys, fallback ) {
		var index;
		if ( ! isObject( object ) ) {
			return fallback || '';
		}
		for ( index = 0; index < keys.length; index += 1 ) {
			if ( hasOwn( object, keys[ index ] ) ) {
				var value = stringValue( object[ keys[ index ] ], '' );
				if ( value !== '' ) {
					return value;
				}
			}
		}
		return fallback || '';
	}

	function normaliseBoolean( value ) {
		if ( value === true || value === false ) {
			return value;
		}
		if ( value === 1 || value === '1' || value === 'true' || value === 'yes' ) {
			return true;
		}
		if ( value === 0 || value === '0' || value === 'false' || value === 'no' ) {
			return false;
		}
		return null;
	}

	function normaliseMethods( value ) {
		var methods = [];
		if ( Array.isArray( value ) ) {
			methods = value;
		} else if ( typeof value === 'string' ) {
			methods = value.split( /[\s,|]+/ );
		} else if ( isObject( value ) ) {
			methods = Object.keys( value ).filter( function ( method ) {
				return value[ method ] !== false && value[ method ] !== null;
			} );
		}
		return methods.map( function ( method ) {
			return stringValue( method, '' ).toUpperCase();
		} ).filter( function ( method, index, allMethods ) {
			return method !== '' && allMethods.indexOf( method ) === index;
		} );
	}

	function normaliseStringCollection( value ) {
		var values = [];
		if ( Array.isArray( value ) ) {
			values = value;
		} else if ( isObject( value ) ) {
			values = Object.keys( value );
		} else if ( typeof value === 'string' || typeof value === 'number' ) {
			values = [ value ];
		}
		return values.map( function ( item ) {
			return stringValue( item, '' );
		} ).filter( function ( item, index, allItems ) {
			return item !== '' && allItems.indexOf( item ) === index;
		} );
	}

	function objectCollectionToArray( collection ) {
		if ( Array.isArray( collection ) ) {
			return collection;
		}
		if ( ! isObject( collection ) ) {
			return [];
		}
		return Object.keys( collection ).map( function ( key ) {
			var item = collection[ key ];
			if ( isObject( item ) ) {
				var clone = Object.assign( {}, item );
				if ( ! hasOwn( clone, 'id' ) ) {
					clone.id = key;
				}
				return clone;
			}
			return { id: key, label: key };
		} );
	}

	function normaliseFieldCollection( value ) {
		if ( Array.isArray( value ) ) {
			return value;
		}
		if ( ! isObject( value ) ) {
			return [];
		}
		return Object.keys( value ).map( function ( path ) {
			var field = value[ path ];
			if ( isObject( field ) ) {
				var clone = Object.assign( {}, field );
				if ( ! hasOwn( clone, 'path' ) ) {
					clone.path = path;
				}
				return clone;
			}
			return { path: path, description: stringValue( field, '' ) };
		} );
	}

	function normaliseSavedField( source ) {
		var field = isObject( source ) ? source : {};
		return {
			description: firstString( field, [ 'description', 'saved_description', 'custom_description' ], typeof source === 'string' ? source : '' ),
			mapping: firstString( field, [ 'mapping', 'saved_mapping', 'nova_content_key' ], '' ),
			manual: normaliseBoolean( field.manual ) === true
		};
	}

	function emptyTemplateConfig() {
		return { selected: [], primary: '', items: Object.create( null ) };
	}

	function decodeJsonPointer( pointer ) {
		if ( typeof pointer !== 'string' || pointer.charAt( 0 ) !== '/' ) {
			return null;
		}
		return pointer.slice( 1 ).split( '/' ).map( function ( segment ) {
			return segment.replace( /~1/g, '/' ).replace( /~0/g, '~' );
		} );
	}

	function parseLegacyTemplatePointer( pointer ) {
		var segments = decodeJsonPointer( pointer );
		if ( ! segments || segments[ 0 ] !== '@templates' || ! segments[ 1 ] ) {
			return null;
		}
		if ( segments.length === 2 ) {
			return { id: segments[ 1 ], marker: true, targetPath: '' };
		}
		if ( segments.length === 4 && segments[ 2 ] === 'fields' && segments[ 3 ] ) {
			return { id: segments[ 1 ], marker: false, targetPath: segments[ 3 ] };
		}
		return null;
	}

	function ensureTemplateItem( config, templateId, slug ) {
		var id = stringValue( templateId, '' );
		if ( ! id ) {
			return null;
		}
		if ( ! isObject( config.items[ id ] ) ) {
			config.items[ id ] = {
				slug: typeof slug === 'string' ? slug : ( id === 'default' ? '' : id ),
				description: '',
				mapping: '',
				fields: Object.create( null )
			};
		}
		if ( ! isObject( config.items[ id ].fields ) ) {
			config.items[ id ].fields = Object.create( null );
		}
		return config.items[ id ];
	}

	function addUniqueString( collection, value ) {
		var text = stringValue( value, '' );
		if ( text && collection.indexOf( text ) === -1 ) {
			collection.push( text );
		}
	}

	function absorbLegacyTemplateField( config, pointer, sourceField ) {
		var parsed = parseLegacyTemplatePointer( pointer );
		if ( ! parsed ) {
			return false;
		}
		var savedField = normaliseSavedField( sourceField );
		var item = ensureTemplateItem( config, parsed.id, parsed.id === 'default' ? '' : parsed.id );
		if ( ! item ) {
			return true;
		}
		if ( parsed.marker ) {
			item.description = savedField.description;
			item.mapping = savedField.mapping;
		} else {
			item.fields[ parsed.targetPath ] = savedField;
		}
		if ( savedField.description.trim() || savedField.mapping.trim() || savedField.manual ) {
			addUniqueString( config.selected, parsed.id );
		}
		return true;
	}

	function normaliseSavedTemplates( value ) {
		var config = emptyTemplateConfig();
		if ( ! isObject( value ) ) {
			return config;
		}
		config.selected = normaliseStringCollection( value.selected );
		config.primary = firstString( value, [ 'primary' ], '' );
		var rawItems = isObject( value.items ) ? value.items : {};
		Object.keys( rawItems ).forEach( function ( itemId ) {
			var sourceItem = isObject( rawItems[ itemId ] ) ? rawItems[ itemId ] : {};
			var item = ensureTemplateItem( config, itemId, firstString( sourceItem, [ 'slug' ], itemId === 'default' ? '' : itemId ) );
			item.description = firstString( sourceItem, [ 'description', 'context' ], '' );
			item.mapping = firstString( sourceItem, [ 'mapping', 'nova_content_key' ], '' );
			normaliseFieldCollection( sourceItem.fields ).forEach( function ( sourceField ) {
				var targetPath = firstString( sourceField, [ 'path', 'target_path', 'pointer', 'name' ], '' );
				if ( targetPath ) {
					item.fields[ targetPath ] = normaliseSavedField( sourceField );
				}
			} );
		} );
		return config;
	}

	function normaliseTemplateCollection( value ) {
		var seen = Object.create( null );
		return objectCollectionToArray( value ).map( function ( source, index ) {
			if ( ! isObject( source ) ) {
				return null;
			}
			var slug = firstString( source, [ 'slug' ], '' );
			var id = firstString( source, [ 'id', 'key' ], slug || ( index === 0 ? 'default' : 'template-' + String( index + 1 ) ) );
			if ( ! id || seen[ id ] ) {
				return null;
			}
			seen[ id ] = true;
			return {
				id: id,
				slug: slug,
				label: firstString( source, [ 'label', 'name', 'title' ], slug || 'Default template' ),
				availability: firstString( source, [ 'availability' ], 'available' ),
				availabilityReason: firstString( source, [ 'availability_reason', 'reason' ], '' ),
				writable: normaliseBoolean( source.writable )
			};
		} ).filter( function ( template ) { return template !== null; } );
	}

	function extractResourceCollection( response ) {
		if ( Array.isArray( response ) ) {
			return response;
		}
		if ( ! isObject( response ) ) {
			return [];
		}
		if ( hasOwn( response, 'resources' ) ) {
			return objectCollectionToArray( response.resources );
		}
		if ( isObject( response.data ) && hasOwn( response.data, 'resources' ) ) {
			return objectCollectionToArray( response.data.resources );
		}
		if ( Array.isArray( response.data ) ) {
			return response.data;
		}
		return [];
	}

	function normaliseSavedConfig( value ) {
		var parsed = value;
		var resources = Object.create( null );
		if ( typeof parsed === 'string' ) {
			try {
				parsed = JSON.parse( parsed );
			} catch ( error ) {
				parsed = {};
			}
		}
		if ( ! isObject( parsed ) ) {
			parsed = {};
		}
		var rawResources = hasOwn( parsed, 'resources' ) ? parsed.resources : ( hasOwn( parsed, 'endpoints' ) ? parsed.endpoints : {} );
		objectCollectionToArray( rawResources ).forEach( function ( sourceResource, index ) {
			if ( ! isObject( sourceResource ) ) {
				return;
			}
			var type = firstString( sourceResource, [ 'type', 'resource_type' ], 'post_type' );
			var postType = firstString( sourceResource, [ 'post_type', 'postType' ], '' );
			var taxonomy = firstString( sourceResource, [ 'taxonomy' ], '' );
			var route = firstString( sourceResource, [ 'route', 'rest_route' ], '' );
			var fallbackId = type + ':' + ( postType || taxonomy || route || String( index + 1 ) );
			var id = firstString( sourceResource, [ 'id', 'resource_id' ], fallbackId );
			var fields = Object.create( null );
			var templates = normaliseSavedTemplates( sourceResource.templates );
			var rawFields = hasOwn( sourceResource, 'fields' ) ? sourceResource.fields : ( hasOwn( sourceResource, 'descriptions' ) ? sourceResource.descriptions : sourceResource.meta_descriptions );
			normaliseFieldCollection( rawFields ).forEach( function ( sourceField ) {
				var path = firstString( sourceField, [ 'path', 'json_pointer', 'pointer', 'name' ], '' );
				if ( path === '' ) {
					return;
				}
				if ( absorbLegacyTemplateField( templates, path, sourceField ) ) {
					return;
				}
				fields[ path ] = normaliseSavedField( sourceField );
			} );
			resources[ id ] = {
				id: id,
				type: type,
				postType: postType,
				taxonomy: taxonomy,
				route: route,
				methods: normaliseMethods( sourceResource.methods ),
				enabled: hasOwn( sourceResource, 'enabled' ) ? normaliseBoolean( sourceResource.enabled ) === true : true,
				enabledExplicit: hasOwn( sourceResource, 'enabled' ),
				fields: fields,
				templates: templates
			};
		} );
		return { version: 4, resources: resources };
	}

	function normaliseExamples( value ) {
		return objectCollectionToArray( value ).map( function ( source ) {
			if ( ! isObject( source ) ) {
				return null;
			}
			var postId = parseInt( firstString( source, [ 'post_id', 'id', 'ID' ], '0' ), 10 );
			if ( ! postId || postId < 1 ) {
				return null;
			}
			return {
				postId: postId,
				label: firstString( source, [ 'title', 'post_title', 'label', 'name' ], 'Post #' + String( postId ) ),
				builder: firstString( source, [ 'builder', 'provider', 'bridge' ], '' )
			};
		} ).filter( function ( example ) { return example !== null; } );
	}

	function normaliseFieldType( field, schema ) {
		var value = hasOwn( field, 'type' ) ? field.type : field.data_type;
		if ( typeof value === 'undefined' ) {
			value = schema.type;
		}
		return normaliseStringCollection( value ).join( ' | ' );
	}

	function makeField( source, fallbackPath ) {
		var field = isObject( source ) ? source : {};
		var schema = isObject( field.schema ) ? field.schema : {};
		var path = firstString( field, [ 'path', 'json_pointer', 'pointer', 'name' ], fallbackPath || '' );
		uid += 1;
		return {
			uid: uid,
			path: path,
			label: firstString( field, [ 'label', 'title', 'name' ], path || 'Unnamed field' ),
			type: normaliseFieldType( field, schema ),
			classification: firstString( field, [ 'classification', 'category', 'kind' ], '' ),
			group: firstString( field, [ 'group', 'field_group' ], '' ),
			source: firstString( field, [ 'source', 'origin' ], '' ),
			origin: firstString( field, [ 'origin' ], '' ),
			availability: firstString( field, [ 'availability', 'field_availability' ], 'available' ),
			baseAvailability: firstString( field, [ 'base_availability', 'baseAvailability' ], '' ),
			availabilityReason: firstString( field, [ 'availability_reason', 'unavailable_reason', 'reason' ], '' ),
			writable: normaliseBoolean( hasOwn( field, 'writable' ) ? field.writable : field.available ),
			couldBeEnabled: normaliseBoolean( field.could_be_enabled ) === true,
			provider: firstString( field, [ 'provider' ], '' ),
			transport: firstString( field, [ 'transport' ], '' ),
			route: firstString( field, [ 'route', 'rest_route' ], '' ),
			requestPath: firstString( field, [ 'request_path', 'requestPath' ], path ),
			targetPath: firstString( field, [ 'target_path', 'targetPath' ], '' ),
			methods: normaliseMethods( field.methods ),
			role: firstString( field, [ 'role' ], '' ),
			template: firstString( field, [ 'template', 'template_name' ], '' ),
			builder: firstString( field, [ 'builder', 'page_builder' ], '' ),
			element: firstString( field, [ 'element', 'element_type', 'block' ], '' ),
			control: firstString( field, [ 'control', 'control_name' ], '' ),
			sourcePostId: firstString( field, [ 'source_post_id' ], '' ),
			sourcePostTitle: firstString( field, [ 'source_post_title' ], '' ),
			selector: firstString( field, [ 'selector' ], isObject( field.selector_data ) ? JSON.stringify( field.selector_data ) : '' ),
			currentValue: firstString( field, [ 'current_value' ], '' ),
			context: firstString( field, [ 'context' ], '' ),
			format: firstString( field, [ 'format' ], '' ),
			choices: normaliseStringCollection( hasOwn( field, 'choices' ) ? field.choices : ( hasOwn( field, 'options' ) ? field.options : schema.enum ) ),
			nativeDescription: firstString( field, [ 'native_description', 'schema_description', 'nativeDescription' ], firstString( schema, [ 'description' ], '' ) ),
			customDescription: firstString( field, [ 'saved_description', 'custom_description' ], '' ),
			mapping: firstString( field, [ 'saved_mapping', 'mapping', 'nova_content_key' ], '' ),
			configurable: hasOwn( field, 'configurable' ) ? normaliseBoolean( field.configurable ) !== false : true,
			manual: normaliseBoolean( field.manual ) === true,
			stale: normaliseBoolean( field.stale ) === true
		};
	}

	function makeResource( source, index ) {
		var resource = isObject( source ) ? source : {};
		var type = firstString( resource, [ 'type', 'resource_type', 'kind' ], 'post_type' );
		var postType = firstString( resource, [ 'post_type' ], '' );
		var taxonomy = firstString( resource, [ 'taxonomy' ], '' );
		var key = postType || taxonomy || firstString( resource, [ 'key', 'slug' ], '' );
		var route = firstString( resource, [ 'route', 'rest_route' ], '' );
		var id = firstString( resource, [ 'id', 'resource_id' ], type + ':' + ( key || String( index + 1 ) ) );
		var selected = normaliseBoolean( hasOwn( resource, 'selected' ) ? resource.selected : resource.enabled );
		var rawFields = resource.fields;
		if ( ! rawFields && isObject( resource.schema ) ) {
			rawFields = resource.schema.fields || resource.schema.properties;
		}
		var templates = normaliseTemplateCollection( resource.templates );
		var legacyTemplates = emptyTemplateConfig();
		var fields = [];
		normaliseFieldCollection( rawFields ).forEach( function ( sourceField, fieldIndex ) {
			var path = firstString( sourceField, [ 'path', 'json_pointer', 'pointer', 'name' ], '/field-' + String( fieldIndex + 1 ) );
			var legacy = parseLegacyTemplatePointer( path );
			if ( legacy ) {
				var legacyField = makeField( sourceField, path );
				var item = ensureTemplateItem( legacyTemplates, legacy.id, legacy.id === 'default' ? '' : legacy.id );
				if ( legacy.marker ) {
					if ( ! templates.some( function ( template ) { return template.id === legacy.id; } ) ) {
						templates.push( {
							id: legacy.id,
							slug: legacyField.template,
							label: legacyField.label,
							availability: legacyField.availability,
							availabilityReason: legacyField.availabilityReason,
							writable: legacyField.writable
						} );
					}
					item.description = legacyField.customDescription;
					item.mapping = legacyField.mapping;
				} else {
					item.fields[ legacy.targetPath ] = {
						description: legacyField.customDescription,
						mapping: legacyField.mapping,
						manual: legacyField.manual
					};
				}
				if ( legacyField.customDescription || legacyField.mapping || legacyField.manual ) {
					addUniqueString( legacyTemplates.selected, legacy.id );
				}
				return;
			}
			fields.push( makeField( sourceField, path ) );
		} );
		if ( selected === null ) {
			selected = type === 'post_type' ? [ 'post', 'page' ].indexOf( postType ) !== -1 : type === 'taxonomy';
		}
		uid += 1;
		return {
			domUid: uid,
			id: id,
			type: type,
			postType: postType,
			taxonomy: taxonomy,
			key: key,
			label: firstString( resource, [ 'label', 'title', 'name' ], key || id ),
			labelPlural: firstString( resource, [ 'label_plural', 'plural_label' ], '' ),
			route: route,
			expectedRoute: firstString( resource, [ 'expected_route' ], route ),
			methods: normaliseMethods( resource.write_methods || resource.methods || resource.allowed_methods ),
			enabled: selected === true,
			enabledExplicit: false,
			showInRest: normaliseBoolean( resource.show_in_rest ),
			usable: normaliseBoolean( hasOwn( resource, 'usable' ) ? resource.usable : resource.writable ),
			status: firstString( resource, [ 'status' ], '' ),
			reason: firstString( resource, [ 'reason' ], '' ),
			message: firstString( resource, [ 'message' ], '' ),
			bridgeExamples: normaliseExamples( resource.bridge_examples ),
			bridgeFieldsUrl: firstString( resource, [ 'bridge_fields_url' ], '' ),
			fields: fields,
			templates: templates,
			templateConfig: legacyTemplates
		};
	}

	function mergeTemplateItems( target, incoming ) {
		if ( ! target || ! incoming ) {
			return target || incoming;
		}
		if ( incoming.slug !== '' || target.slug === '' ) {
			target.slug = incoming.slug;
		}
		if ( incoming.description !== '' ) {
			target.description = incoming.description;
		}
		if ( incoming.mapping !== '' ) {
			target.mapping = incoming.mapping;
		}
		Object.keys( incoming.fields || {} ).forEach( function ( pointer ) {
			target.fields[ pointer ] = normaliseSavedField( incoming.fields[ pointer ] );
		} );
		return target;
	}

	function reconcileTemplateConfig( resource, sourceConfig ) {
		var source = sourceConfig || emptyTemplateConfig();
		var output = emptyTemplateConfig();
		var idMap = Object.create( null );
		var descriptors = resource.templates || [];
		function resolveId( oldId, item ) {
			var exact = descriptors.find( function ( template ) { return template.id === oldId; } );
			if ( exact ) {
				return exact.id;
			}
			var slug = item && typeof item.slug === 'string' ? item.slug : ( oldId === 'default' ? '' : oldId );
			var matched = descriptors.find( function ( template ) {
				return template.slug === slug || ( oldId === 'default' && template.slug === '' );
			} );
			return matched ? matched.id : oldId;
		}
		Object.keys( source.items || {} ).forEach( function ( oldId ) {
			var sourceItem = source.items[ oldId ];
			var newId = resolveId( oldId, sourceItem );
			idMap[ oldId ] = newId;
			var descriptor = descriptors.find( function ( template ) { return template.id === newId; } );
			var targetItem = ensureTemplateItem( output, newId, descriptor ? descriptor.slug : sourceItem.slug );
			mergeTemplateItems( targetItem, sourceItem );
		} );
		source.selected.forEach( function ( oldId ) { addUniqueString( output.selected, idMap[ oldId ] || resolveId( oldId, source.items[ oldId ] ) ); } );
		output.primary = source.primary ? ( idMap[ source.primary ] || resolveId( source.primary, source.items[ source.primary ] ) ) : '';
		descriptors.forEach( function ( template ) {
			if ( output.items[ template.id ] ) {
				output.items[ template.id ].slug = template.slug;
			}
		} );
		return output;
	}

	function deterministicTemplateOrder( resource, selected ) {
		var ordered = [];
		( resource.templates || [] ).forEach( function ( template ) {
			if ( selected.indexOf( template.id ) !== -1 ) {
				ordered.push( template.id );
			}
		} );
		selected.slice().sort().forEach( function ( id ) { addUniqueString( ordered, id ); } );
		return ordered;
	}

	function normaliseTemplateSelection( resource ) {
		var config = resource.templateConfig || emptyTemplateConfig();
		config.selected = normaliseStringCollection( config.selected );
		if ( config.selected.length === 0 ) {
			config.primary = '';
		} else if ( config.selected.length === 1 ) {
			config.primary = config.selected[ 0 ];
		} else if ( config.selected.indexOf( config.primary ) === -1 ) {
			config.primary = deterministicTemplateOrder( resource, config.selected )[ 0 ] || '';
		}
		resource.templateConfig = config;
		return config;
	}

	function savedResourceMatches( resource, savedResource ) {
		if ( ! savedResource || resource.type !== savedResource.type ) {
			return false;
		}
		if ( resource.postType && savedResource.postType ) {
			return resource.postType === savedResource.postType;
		}
		if ( resource.taxonomy && savedResource.taxonomy ) {
			return resource.taxonomy === savedResource.taxonomy;
		}
		return Boolean( resource.route && savedResource.route && resource.route === savedResource.route );
	}

	function mergeSavedConfig( discoveredResources, savedConfig ) {
		var savedIds = Object.keys( savedConfig.resources );
		discoveredResources.forEach( function ( resource ) {
			var savedResource = hasOwn( savedConfig.resources, resource.id ) ? savedConfig.resources[ resource.id ] : null;
			if ( ! savedResource ) {
				savedIds.some( function ( savedId ) {
					if ( savedResourceMatches( resource, savedConfig.resources[ savedId ] ) ) {
						savedResource = savedConfig.resources[ savedId ];
						return true;
					}
					return false;
				} );
			}
			if ( ! savedResource ) {
				resource.templateConfig = reconcileTemplateConfig( resource, resource.templateConfig );
				normaliseTemplateSelection( resource );
				return;
			}
			resource.enabled = savedResource.enabled;
			resource.enabledExplicit = savedResource.enabledExplicit;
			var knownPaths = Object.create( null );
			resource.fields.forEach( function ( field ) {
				knownPaths[ field.path ] = true;
				if ( hasOwn( savedResource.fields, field.path ) ) {
					field.customDescription = savedResource.fields[ field.path ].description;
					field.mapping = savedResource.fields[ field.path ].mapping;
					field.manual = savedResource.fields[ field.path ].manual;
				}
			} );
			Object.keys( savedResource.fields ).forEach( function ( path ) {
				if ( knownPaths[ path ] ) {
					return;
				}
				var isBuilder = /(?:^|\/|_)(?:@builders?|builder|gutenberg|elementor|wpbakery|breakdance|avada|divi|beaver)(?:\/|_|$)/i.test( path );
				resource.fields.push( makeField( {
					path: path,
					label: path,
					source: isBuilder ? 'builder' : 'other',
					transport: isBuilder ? 'nova_builder' : 'unknown',
					saved_description: savedResource.fields[ path ].description,
					saved_mapping: savedResource.fields[ path ].mapping,
					manual: savedResource.fields[ path ].manual,
					stale: true
				}, path ) );
			} );
			resource.templateConfig = reconcileTemplateConfig( resource, savedResource.templates );
			normaliseTemplateSelection( resource );
		} );
		return discoveredResources;
	}

	function resourceAvailability( resource ) {
		var status = resource.status.toLowerCase().replace( /[\s-]+/g, '_' );
		if ( ( resource.type === 'post_type' && resource.showInRest === false ) || resource.usable === false ) {
			return 'unavailable';
		}
		if ( [ 'unavailable', 'disabled', 'inaccessible', 'not_usable', 'error' ].indexOf( status ) !== -1 ) {
			return 'unavailable';
		}
		return 'usable';
	}

	function fieldAvailability( field ) {
		var status = field.availability.toLowerCase().replace( /[\s-]+/g, '_' );
		if ( field.stale || [ 'stale', 'removed', 'missing', 'unavailable' ].indexOf( status ) !== -1 ) {
			return 'unavailable';
		}
		if ( field.source === 'template' || field.transport === 'context_selector' ) {
			return 'available';
		}
		if ( field.couldBeEnabled || field.writable === false || [ 'potential', 'available_if_enabled', 'could_be_available', 'not_exposed', 'disabled' ].indexOf( status ) !== -1 ) {
			return 'potential';
		}
		return 'available';
	}

	function fieldGroup( field ) {
		var explicit = ( field.group || field.source || '' ).toLowerCase().replace( /[\s-]+/g, '_' );
		var provider = field.provider.toLowerCase().replace( /[\s-]+/g, '_' );
		var haystack = [ field.path, field.source, field.origin, field.transport, field.builder, field.role ].join( ' ' ).toLowerCase();
		if ( explicit === 'template' || field.transport === 'context_selector' ) {
			return 'template';
		}
		if ( [ 'builder', 'page_builder', 'pagebuilder', 'blocks', 'gutenberg' ].indexOf( explicit ) !== -1 || /(?:@builders?|elementor|wpbakery|breakdance|avada|fusion|divi|beaver|gutenberg)/.test( haystack ) ) {
			return 'builder';
		}
		if ( explicit === 'seo' || [ 'seopress', 'yoast', 'rank_math', 'rankmath', 'aioseo' ].indexOf( provider ) !== -1 || /(?:^|\/|_)@?seo(?:\/|_|$)/.test( haystack ) ) {
			return 'seo';
		}
		if ( [ 'acf', 'meta', 'acf_meta', 'acf_custom', 'custom_field', 'custom_fields', 'post_meta', 'term_meta' ].indexOf( explicit ) !== -1 || /^\/(?:acf|meta|meta_all)(?:\/|$)/.test( field.path ) ) {
			return 'acf_custom';
		}
		if ( [ 'media', 'media_taxonomy', 'taxonomy', 'taxonomies', 'term', 'terms' ].indexOf( explicit ) !== -1 || /^\/(?:featured_media|categories|tags|terms|image)(?:\/|$)/.test( field.path ) ) {
			return 'media_taxonomy';
		}
		if ( [ 'core', 'core_content', 'wordpress', 'content', 'template' ].indexOf( explicit ) !== -1 || /^\/(?:title|content|excerpt|slug|status|date|date_gmt|author|template|menu_order|comment_status|ping_status)(?:\/|$)/.test( field.path ) ) {
			return 'core';
		}
		return 'other';
	}

	function createElement( tagName, className, text ) {
		var element = document.createElement( tagName );
		if ( className ) {
			element.className = className;
		}
		if ( typeof text === 'string' ) {
			element.textContent = text;
		}
		return element;
	}

	function clearElement( element ) {
		while ( element.firstChild ) {
			element.removeChild( element.firstChild );
		}
	}

	function appendBadge( parent, label, modifier ) {
		var badge = createElement( 'span', 'nova-content-context__badge nova-content-context__badge--' + modifier, label );
		parent.appendChild( badge );
		return badge;
	}

	function appendDefinition( list, term, description, code ) {
		list.appendChild( createElement( 'dt', '', term ) );
		var definition = createElement( 'dd' );
		definition.appendChild( createElement( code ? 'code' : 'span', '', description || '—' ) );
		list.appendChild( definition );
	}

	function fieldSearchText( field ) {
		return [ field.path, field.label, field.type, field.source, field.provider, field.transport, field.route, field.requestPath, field.targetPath, field.mapping, field.customDescription, field.builder, field.element, field.control, field.selector, field.currentValue, field.context, field.format ].join( ' ' ).toLowerCase();
	}

	function resourceSearchText( resource ) {
		var templates = ( resource.templates || [] ).map( function ( template ) { return [ template.id, template.slug, template.label, template.availability, template.availabilityReason ].join( ' ' ); } ).join( ' ' );
		return [ resource.id, resource.type, resource.postType, resource.taxonomy, resource.route, resource.expectedRoute, resource.label, resource.labelPlural, resource.status, resource.reason, resource.message, templates, resource.fields.map( fieldSearchText ).join( ' ' ) ].join( ' ' ).toLowerCase();
	}

	function resourceMatches( resource, query, statusFilter, selectedResource ) {
		if ( selectedResource && resource.id !== selectedResource ) {
			return false;
		}
		if ( query && resourceSearchText( resource ).indexOf( query ) === -1 ) {
			return false;
		}
		if ( statusFilter === 'usable' ) {
			return resourceAvailability( resource ) === 'usable';
		}
		if ( statusFilter === 'unavailable' ) {
			return resourceAvailability( resource ) === 'unavailable';
		}
		return true;
	}

	function serialiseTemplateConfig( sourceConfig ) {
		var config = sourceConfig || emptyTemplateConfig();
		var output = {
			selected: normaliseStringCollection( config.selected ),
			primary: stringValue( config.primary, '' ),
			items: Object.create( null )
		};
		Object.keys( config.items || {} ).forEach( function ( itemId ) {
			var sourceItem = isObject( config.items[ itemId ] ) ? config.items[ itemId ] : {};
			var fields = Object.create( null );
			Object.keys( sourceItem.fields || {} ).forEach( function ( pointer ) {
				var field = normaliseSavedField( sourceItem.fields[ pointer ] );
				fields[ pointer ] = {
					description: field.description,
					mapping: field.mapping,
					manual: Boolean( field.manual )
				};
			} );
			var keepItem = output.selected.indexOf( itemId ) !== -1
				|| stringValue( sourceItem.description, '' ).trim() !== ''
				|| stringValue( sourceItem.mapping, '' ).trim() !== ''
				|| Object.keys( fields ).some( function ( pointer ) {
					var field = fields[ pointer ];
					return field.description.trim() !== '' || field.mapping.trim() !== '' || field.manual;
				} );
			if ( ! keepItem ) {
				return;
			}
			output.items[ itemId ] = {
				slug: stringValue( sourceItem.slug, itemId === 'default' ? '' : itemId ),
				description: stringValue( sourceItem.description, '' ),
				mapping: stringValue( sourceItem.mapping, '' ),
				fields: fields
			};
		} );
		output.selected.forEach( function ( itemId ) {
			if ( ! hasOwn( output.items, itemId ) ) {
				output.items[ itemId ] = { slug: itemId === 'default' ? '' : itemId, description: '', mapping: '', fields: Object.create( null ) };
			}
		} );
		if ( output.selected.indexOf( output.primary ) === -1 ) {
			output.primary = output.selected.length === 1 ? output.selected[ 0 ] : '';
		}
		return output;
	}

	function buildPayloadObject( resources, preservedConfig ) {
		var payloadResources = Object.create( null );
		if ( preservedConfig && isObject( preservedConfig.resources ) ) {
			Object.keys( preservedConfig.resources ).forEach( function ( resourceId ) {
				var saved = preservedConfig.resources[ resourceId ];
				if ( ! isObject( saved ) || [ 'post_type', 'taxonomy', 'route' ].indexOf( saved.type ) === -1 ) {
					return;
				}
				var savedFields = Object.create( null );
				Object.keys( saved.fields || {} ).forEach( function ( path ) {
					var field = saved.fields[ path ];
					if ( ! isObject( field ) ) {
						return;
					}
					savedFields[ path ] = {
						description: stringValue( field.description, '' ),
						mapping: stringValue( field.mapping, '' ),
						manual: Boolean( field.manual )
					};
				} );
				var preserved = {
					id: saved.id || resourceId,
					type: saved.type,
					enabled: Boolean( saved.enabled ),
					fields: savedFields
				};
				if ( saved.type === 'post_type' ) {
					preserved.post_type = saved.postType;
					preserved.templates = serialiseTemplateConfig( saved.templates );
				} else if ( saved.type === 'taxonomy' ) {
					preserved.taxonomy = saved.taxonomy;
				} else {
					preserved.route = saved.route;
					preserved.methods = normaliseMethods( saved.methods );
				}
				payloadResources[ resourceId ] = preserved;
			} );
		}
		resources.forEach( function ( resource ) {
			if ( [ 'post_type', 'taxonomy' ].indexOf( resource.type ) === -1 ) {
				return;
			}
			var fields = Object.create( null );
			resource.fields.forEach( function ( field ) {
				var description = stringValue( field.customDescription, '' );
				var mapping = stringValue( field.mapping, '' );
				if ( field.path === '' || ( description.trim() === '' && mapping.trim() === '' && ! field.manual ) ) {
					return;
				}
				fields[ field.path ] = { description: description, mapping: mapping, manual: Boolean( field.manual ) };
			} );
			var output = {
				id: resource.id,
				type: resource.type,
				enabled: Boolean( resource.enabled ),
				fields: fields
			};
			if ( resource.type === 'post_type' ) {
				output.post_type = resource.postType;
				output.templates = serialiseTemplateConfig( normaliseTemplateSelection( resource ) );
			} else {
				output.taxonomy = resource.taxonomy;
			}
			payloadResources[ resource.id ] = output;
		} );
		return { version: 4, resources: payloadResources };
	}

	function initialise() {
		var root = document.getElementById( APP_ID );
		var payload = document.getElementById( PAYLOAD_ID );
		var settings = isObject( window.NovaContentContextAdmin ) ? window.NovaContentContextAdmin : {};
		if ( ! root || ! payload ) {
			return;
		}
		var state = {
			settings: settings,
			savedConfig: normaliseSavedConfig( settings.savedConfig ),
			resources: [], query: '', filter: 'usable', selectedResource: '',
			openResources: Object.create( null ), bridgeLoading: Object.create( null ), bridgeStatus: Object.create( null ),
			openGroups: Object.create( null ), openTechnical: Object.create( null ), guidanceOpen: Object.create( null ),
			openBuilders: Object.create( null ), openTemplateOverrides: Object.create( null ), templateErrors: Object.create( null ),
			requestNumber: 0, searchTimer: null
		};
		root.classList.add( 'nova-content-context' );
		root.removeAttribute( 'aria-live' );

		function syncPayload() {
			payload.value = JSON.stringify( buildPayloadObject( state.resources, state.savedConfig ) );
		}

		function renderLoading() {
			clearElement( root );
			root.setAttribute( 'aria-busy', 'true' );
			var loading = createElement( 'div', 'nova-content-context__state nova-content-context__state--loading' );
			loading.setAttribute( 'role', 'status' );
			loading.setAttribute( 'aria-live', 'polite' );
			var spinner = createElement( 'span', 'spinner is-active' );
			spinner.setAttribute( 'aria-hidden', 'true' );
			loading.appendChild( spinner );
			loading.appendChild( createElement( 'p', '', 'Inspecting relevant publishing endpoints…' ) );
			root.appendChild( loading );
		}

		function renderError( message ) {
			clearElement( root );
			root.removeAttribute( 'aria-busy' );
			var notice = createElement( 'div', 'notice notice-error nova-content-context__state nova-content-context__state--error' );
			var retry = createElement( 'button', 'button button-secondary', 'Retry discovery' );
			notice.setAttribute( 'role', 'alert' );
			retry.type = 'button';
			retry.addEventListener( 'click', loadDiscovery );
			notice.appendChild( createElement( 'h2', '', 'Could not load publishing endpoints' ) );
			notice.appendChild( createElement( 'p', '', message || 'Endpoint discovery failed.' ) );
			notice.appendChild( retry );
			root.appendChild( notice );
		}

		function renderHeader( parent ) {
			var header = createElement( 'div', 'nova-content-context__header' );
			header.appendChild( createElement( 'h2', '', 'Publishing endpoints and field mapping' ) );
			header.appendChild( createElement( 'p', 'description', 'Choose where NOVA may publish, map each API field to a NOVA source, and add guidance only where it is useful.' ) );
			parent.appendChild( header );
		}

		function renderToolbar( parent ) {
			var toolbar = createElement( 'div', 'nova-content-context__toolbar' );
			toolbar.dataset.control = 'resource-toolbar';
			var searchGroup = createElement( 'div', 'nova-content-context__control nova-content-context__control--search' );
			var statusGroup = createElement( 'div', 'nova-content-context__control nova-content-context__control--status' );
			var endpointGroup = createElement( 'div', 'nova-content-context__control nova-content-context__control--endpoint' );
			var search = createElement( 'input', 'regular-text' );
			var status = createElement( 'select' );
			var endpoint = createElement( 'select' );
			var usableCount = state.resources.filter( function ( resource ) { return resourceAvailability( resource ) === 'usable'; } ).length;
			var unavailableCount = state.resources.length - usableCount;

			var searchLabel = createElement( 'label', '', 'Search endpoints and fields' );
			searchLabel.htmlFor = 'nova-content-context-search';
			search.id = searchLabel.htmlFor;
			search.type = 'search';
			search.dataset.control = 'resource-search';
			search.placeholder = 'Search route, field or provider';
			search.value = state.query;
			search.addEventListener( 'input', function () {
				state.query = search.value.toLowerCase().trim();
				if ( state.searchTimer ) {
					window.clearTimeout( state.searchTimer );
				}
				state.searchTimer = window.setTimeout( function () { state.searchTimer = null; renderResourceResults(); }, 120 );
			} );
			searchGroup.appendChild( searchLabel );
			searchGroup.appendChild( search );

			var statusLabel = createElement( 'label', '', 'API status' );
			statusLabel.htmlFor = 'nova-content-context-status-filter';
			status.id = statusLabel.htmlFor;
			status.dataset.control = 'resource-status-filter';
			[
				[ 'all', 'All (' + String( state.resources.length ) + ')' ],
				[ 'usable', 'Usable (' + String( usableCount ) + ')' ],
				[ 'unavailable', 'Needs API access (' + String( unavailableCount ) + ')' ]
			].forEach( function ( optionData ) {
				var option = createElement( 'option', '', optionData[ 1 ] );
				option.value = optionData[ 0 ];
				status.appendChild( option );
			} );
			status.value = state.filter;
			status.addEventListener( 'change', function () { state.filter = status.value; state.selectedResource = ''; renderApplication(); } );
			statusGroup.appendChild( statusLabel );
			statusGroup.appendChild( status );

			var endpointLabel = createElement( 'label', '', 'Endpoint' );
			endpointLabel.htmlFor = 'nova-content-context-endpoint';
			endpoint.id = endpointLabel.htmlFor;
			endpoint.dataset.control = 'resource-selector';
			var allOption = createElement( 'option', '', 'All endpoints' );
			allOption.value = '';
			endpoint.appendChild( allOption );
			state.resources.slice().sort( function ( left, right ) {
				return left.label.localeCompare( right.label, undefined, { sensitivity: 'base' } );
			} ).forEach( function ( resource ) {
				var option = createElement( 'option', '', resource.label + ' (' + ( resource.postType || resource.taxonomy || resource.id ) + ')' );
				option.value = resource.id;
				endpoint.appendChild( option );
			} );
			endpoint.value = state.selectedResource;
			endpoint.addEventListener( 'change', function () {
				state.selectedResource = endpoint.value;
				if ( state.selectedResource ) {
					state.filter = 'all';
					state.openResources[ state.selectedResource ] = true;
					renderApplication();
				} else {
					renderResourceResults();
				}
			} );
			endpointGroup.appendChild( endpointLabel );
			endpointGroup.appendChild( endpoint );
			toolbar.appendChild( searchGroup );
			toolbar.appendChild( statusGroup );
			toolbar.appendChild( endpointGroup );
			parent.appendChild( toolbar );
		}

		function transportLabel( field ) {
			var provider = field.provider || 'Unknown provider';
			var transport = field.transport || 'Unknown transport';
			return provider.toLowerCase() === transport.toLowerCase() ? provider : provider + ' / ' + transport;
		}

		function providerDisplayName( value ) {
			var provider = stringValue( value, '' ).toLowerCase().replace( /[\s-]+/g, '_' );
			var names = { seopress: 'SEOPress', yoast: 'Yoast SEO', rank_math: 'Rank Math', rankmath: 'Rank Math', aioseo: 'AIOSEO' };
			return names[ provider ] || stringValue( value, '' ).replace( /_/g, ' ' ) || 'SEO provider';
		}

		function seoFieldLabel( field ) {
			var value = [ field.role, field.path, field.label ].join( ' ' ).toLowerCase();
			if ( /(?:meta[_ -]?)?(?:description|desc)(?:\b|_)/.test( value ) ) {
				return 'Meta description';
			}
			if ( /(?:meta[_ -]?)?title(?:\b|_)/.test( value ) ) {
				return 'Meta title';
			}
			return field.label || field.path;
		}

		function displayFieldLabel( field ) {
			return fieldGroup( field ) === 'seo' ? seoFieldLabel( field ) : ( field.label || field.path );
		}

		function renderTechnicalDetails( resource, field, parent ) {
			var key = resource.id + '|' + field.path;
			var details = createElement( 'details', 'nova-content-context__technical' );
			var list = createElement( 'dl', 'nova-content-context__technical-body' );
			details.open = Boolean( state.openTechnical[ key ] );
			details.dataset.resourceId = resource.id;
			details.dataset.fieldPath = field.path;
			details.dataset.control = 'technical-details';
			details.appendChild( createElement( 'summary', '', 'Technical details' ) );
			appendDefinition( list, 'JSON Pointer', field.path, true );
			appendDefinition( list, 'Availability', fieldAvailability( field ) === 'available' ? 'Available through API' : ( fieldAvailability( field ) === 'potential' ? 'Could be exposed through API' : 'Not currently available' ), false );
			appendDefinition( list, 'Provider / transport', transportLabel( field ), false );
			appendDefinition( list, 'REST route', field.route || resource.route || resource.expectedRoute, true );
			appendDefinition( list, 'Write methods', ( field.methods.length ? field.methods : resource.methods ).join( ', ' ), false );
			appendDefinition( list, 'Request body path', field.requestPath || field.path, true );
			if ( field.targetPath ) { appendDefinition( list, 'Target path', field.targetPath, true ); }
			if ( field.type ) { appendDefinition( list, 'Detected type', field.type, true ); }
			if ( field.nativeDescription ) { appendDefinition( list, 'Detected description', field.nativeDescription, false ); }
			if ( field.availabilityReason ) { appendDefinition( list, 'Availability note', field.availabilityReason.replace( /[_-]+/g, ' ' ), false ); }
			if ( field.choices.length ) { appendDefinition( list, 'Accepted values', field.choices.join( ', ' ), true ); }
			if ( fieldGroup( field ) === 'builder' ) {
				appendDefinition( list, 'Source document', field.sourcePostTitle ? field.sourcePostTitle + ( field.sourcePostId ? ' (#' + field.sourcePostId + ')' : '' ) : ( field.sourcePostId ? '#' + field.sourcePostId : '' ), false );
				appendDefinition( list, 'Bridge selector', field.selector, true );
				appendDefinition( list, 'Current value', field.currentValue, false );
				appendDefinition( list, 'Bridge format', field.format || field.context, false );
			}
			details.appendChild( list );
			details.addEventListener( 'toggle', function () { state.openTechnical[ key ] = details.open; } );
			parent.appendChild( details );
		}

		function renderMappingRow( resource, field, parent, options ) {
			var settings = options || {};
			var groupId = settings.groupId || fieldGroup( field );
			var fieldLabel = settings.label || displayFieldLabel( field );
			var availability = fieldAvailability( field );
			var row = createElement( 'article', 'nova-content-context__mapping-row nova-content-context__mapping-row--' + availability );
			var identity = createElement( 'div', 'nova-content-context__mapping-identity' );
			var mapping = createElement( 'div', 'nova-content-context__mapping-control' );
			var guidanceWrap = createElement( 'div', 'nova-content-context__mapping-guidance' );
			var fieldId = 'nova-content-context-field-' + String( field.uid );
			var mappingId = fieldId + '-mapping';
			var guidanceId = fieldId + '-guidance';
			var mappingLabel = createElement( 'label', 'screen-reader-text', 'NOVA source for ' + fieldLabel );
			var mappingInput = createElement( 'input', 'regular-text' );
			var guidanceKey = resource.id + '|' + field.path;
			var guidanceInitiallyOpen = hasOwn( state.guidanceOpen, guidanceKey ) ? Boolean( state.guidanceOpen[ guidanceKey ] ) : ( Boolean( field.customDescription ) || [ 'acf_custom', 'builder', 'other' ].indexOf( groupId ) !== -1 );
			var toggleLabel = createElement( 'label', 'nova-content-context__guidance-toggle' );
			var toggle = createElement( 'input' );
			row.id = fieldId;
			row.dataset.resourceId = resource.id;
			row.dataset.fieldPath = field.path;
			row.dataset.groupId = groupId;
			identity.appendChild( createElement( 'strong', '', fieldLabel ) );
			var compactBadges = createElement( 'span', 'nova-content-context__field-badges' );
			if ( availability !== 'available' ) { appendBadge( compactBadges, availability === 'potential' ? 'Could be exposed' : 'Unavailable', availability ); }
			if ( field.stale ) { appendBadge( compactBadges, 'Saved field', 'stale' ); }
			identity.appendChild( compactBadges );
			mappingLabel.htmlFor = mappingId;
			mappingInput.id = mappingId;
			mappingInput.type = 'text';
			mappingInput.value = field.mapping;
			mappingInput.placeholder = 'NOVA source, e.g. article.body';
			mappingInput.disabled = ! field.configurable;
			mappingInput.dataset.control = 'field-mapping';
			mappingInput.dataset.resourceId = resource.id;
			mappingInput.dataset.fieldPath = field.path;
			mappingInput.addEventListener( 'input', function () { field.mapping = mappingInput.value; syncPayload(); } );
			mapping.appendChild( mappingLabel );
			mapping.appendChild( mappingInput );
			toggle.type = 'checkbox';
			toggle.checked = guidanceInitiallyOpen;
			toggle.dataset.control = 'guidance-toggle';
			toggle.dataset.resourceId = resource.id;
			toggle.dataset.fieldPath = field.path;
			toggleLabel.appendChild( toggle );
			toggleLabel.appendChild( document.createTextNode( ' Add writing guidance' ) );
			guidanceWrap.appendChild( toggleLabel );
			var guidanceControl = createElement( 'div', 'nova-content-context__guidance-control' );
			var guidanceLabel = createElement( 'label', 'screen-reader-text', 'Writing guidance for ' + fieldLabel );
			var guidance = createElement( 'textarea', 'large-text' );
			guidanceLabel.htmlFor = guidanceId;
			guidance.id = guidanceId;
			guidance.rows = 2;
			guidance.value = field.customDescription;
			guidance.placeholder = 'What belongs here, and how should NOVA write it?';
			guidance.disabled = ! field.configurable;
			guidance.dataset.control = 'field-guidance';
			guidance.dataset.resourceId = resource.id;
			guidance.dataset.fieldPath = field.path;
			guidance.addEventListener( 'input', function () { field.customDescription = guidance.value; syncPayload(); } );
			guidanceControl.hidden = ! guidanceInitiallyOpen;
			guidanceControl.appendChild( guidanceLabel );
			guidanceControl.appendChild( guidance );
			guidanceWrap.appendChild( guidanceControl );
			toggle.addEventListener( 'change', function () {
				state.guidanceOpen[ guidanceKey ] = toggle.checked;
				guidanceControl.hidden = ! toggle.checked;
				if ( toggle.checked ) { guidance.focus(); }
			} );
			row.appendChild( identity );
			row.appendChild( mapping );
			row.appendChild( guidanceWrap );
			var actions = createElement( 'div', 'nova-content-context__mapping-actions' );
			if ( field.manual || field.stale ) {
				var remove = createElement( 'button', 'button-link-delete nova-content-context__remove', field.stale ? 'Remove saved field' : 'Remove manual field' );
				remove.type = 'button';
				remove.dataset.control = 'remove-field';
				remove.dataset.resourceId = resource.id;
				remove.dataset.fieldPath = field.path;
				remove.addEventListener( 'click', function () {
					resource.fields = resource.fields.filter( function ( candidate ) { return candidate !== field; } );
					syncPayload();
					state.openResources[ resource.id ] = true;
					renderApplication();
				} );
				actions.appendChild( remove );
			}
			row.appendChild( actions );
			renderTechnicalDetails( resource, field, row );
			parent.appendChild( row );
		}

		function seoGroupLabel( fields ) {
			var provider = fields.map( function ( field ) { return field.provider; } ).filter( Boolean )[ 0 ];
			if ( ! provider ) {
				var text = fields.map( function ( field ) { return field.path; } ).join( ' ' ).toLowerCase();
				provider = /seopress/.test( text ) ? 'seopress' : ( /yoast/.test( text ) ? 'yoast' : ( /rank[_-]?math/.test( text ) ? 'rank_math' : ( /aioseo/.test( text ) ? 'aioseo' : '' ) ) );
			}
			return providerDisplayName( provider ) + ' detected';
		}

		function renderFieldGroups( resource, parent ) {
			var groups = Object.create( null );
			var ordinaryFields = resource.fields.filter( function ( field ) { return field.path !== '/template' && fieldGroup( field ) !== 'template'; } );
			FIELD_GROUPS.forEach( function ( definition ) { groups[ definition.id ] = []; } );
			ordinaryFields.forEach( function ( field ) {
				var group = fieldGroup( field );
				if ( hasOwn( groups, group ) ) { groups[ group ].push( field ); }
			} );
			parent.appendChild( createElement( 'h4', 'nova-content-context__inventory-heading', 'Field mapping (' + String( ordinaryFields.length ) + ')' ) );
			if ( ordinaryFields.length === 0 ) {
				parent.appendChild( createElement( 'p', 'nova-content-context__empty-fields', 'No relevant API fields were reported for this endpoint.' ) );
				return;
			}
			FIELD_GROUPS.forEach( function ( definition ) {
				var fields = groups[ definition.id ];
				if ( fields.length === 0 ) { return; }
				var groupKey = resource.id + '|' + definition.id;
				var matching = state.query ? fields.filter( function ( field ) { return fieldSearchText( field ).indexOf( state.query ) !== -1; } ) : fields;
				if ( state.query && matching.length === 0 ) { return; }
				var section = createElement( 'details', 'nova-content-context__mapping-group nova-content-context__mapping-group--' + definition.id );
				var summary = createElement( 'summary', 'nova-content-context__mapping-group-summary' );
				var taxonomyStructureGroup = definition.id === 'media_taxonomy' && resource.type === 'taxonomy';
				var groupLabel = definition.id === 'seo' ? seoGroupLabel( fields ) : ( taxonomyStructureGroup ? 'Media & category structure' : definition.label );
				var groupDescription = taxonomyStructureGroup ? 'Category hierarchy, images and related term structure.' : definition.description;
				section.dataset.resourceId = resource.id;
				section.dataset.groupId = definition.id;
				section.open = state.query ? true : ( hasOwn( state.openGroups, groupKey ) ? state.openGroups[ groupKey ] : definition.id === 'core' );
				summary.appendChild( createElement( 'span', '', groupLabel ) );
				appendBadge( summary, String( matching.length ), 'neutral' );
				section.appendChild( summary );
				var rendered = false;
				function renderRows() {
					if ( rendered ) { return; }
					rendered = true;
					var body = createElement( 'div', 'nova-content-context__mapping-group-body' );
					body.appendChild( createElement( 'p', 'description nova-content-context__field-group-description', groupDescription ) );
					var columnHeadings = createElement( 'div', 'nova-content-context__mapping-headings' );
					columnHeadings.appendChild( createElement( 'span', '', 'API field' ) );
					columnHeadings.appendChild( createElement( 'span', '', 'NOVA source' ) );
					columnHeadings.appendChild( createElement( 'span', '', 'Guidance' ) );
					columnHeadings.appendChild( createElement( 'span', '', 'Details' ) );
					body.appendChild( columnHeadings );
					var rows = createElement( 'div', 'nova-content-context__mapping-rows' );
					matching.forEach( function ( field ) { renderMappingRow( resource, field, rows, { groupId: definition.id } ); } );
					body.appendChild( rows );
					section.appendChild( body );
				}
				section.addEventListener( 'toggle', function () { state.openGroups[ groupKey ] = section.open; if ( section.open ) { renderRows(); } } );
				if ( section.open ) { renderRows(); }
				parent.appendChild( section );
			} );
		}

		function templateItemHasContent( item ) {
			return Boolean( item && ( stringValue( item.description, '' ).trim() || stringValue( item.mapping, '' ).trim() || Object.keys( item.fields || {} ).length ) );
		}

		function templateDescriptorsForDisplay( resource ) {
			var templates = ( resource.templates || [] ).slice();
			var known = Object.create( null );
			templates.forEach( function ( template ) { known[ template.id ] = true; } );
			Object.keys( resource.templateConfig.items || {} ).forEach( function ( itemId ) {
				var item = resource.templateConfig.items[ itemId ];
				if ( ! known[ itemId ] && ( resource.templateConfig.selected.indexOf( itemId ) !== -1 || templateItemHasContent( item ) ) ) {
					templates.push( { id: itemId, slug: item.slug, label: item.slug || ( itemId === 'default' ? 'Default template' : itemId ), availability: 'saved', availabilityReason: 'Saved template not reported by current discovery.', writable: null } );
				}
			} );
			return templates;
		}

		function eligibleTemplateFields( resource ) {
			return resource.fields.filter( function ( field ) {
				if ( field.path === '/template' || fieldGroup( field ) === 'media_taxonomy' || field.configurable === false ) { return false; }
				if ( field.classification.toLowerCase() === 'textual' || fieldGroup( field ) === 'builder' ) { return true; }
				if ( /(?:string|text|html|rich|object)/i.test( field.type ) ) { return true; }
				return /^(?:\/title|\/content|\/excerpt|\/acf\/|\/meta\/|\/@seo\/)/.test( field.path );
			} );
		}

		function renderTemplateOverrideRow( resource, template, targetPath, override, parent ) {
			var baseField = resource.fields.find( function ( field ) { return field.path === targetPath; } );
			var row = createElement( 'div', 'nova-content-context__override-row' );
			var identity = createElement( 'div', 'nova-content-context__mapping-identity' );
			var safeId = String( resource.domUid ) + '-' + String( template.id ).replace( /[^a-z0-9_-]/gi, '-' ) + '-' + String( uid += 1 );
			var mappingLabel = createElement( 'label', 'screen-reader-text', 'NOVA source override for ' + ( baseField ? displayFieldLabel( baseField ) : targetPath ) );
			var mapping = createElement( 'input', 'regular-text' );
			var guidanceLabel = createElement( 'label', 'screen-reader-text', 'Template guidance override for ' + ( baseField ? displayFieldLabel( baseField ) : targetPath ) );
			var guidance = createElement( 'textarea', 'large-text' );
			var remove = createElement( 'button', 'button-link-delete', 'Remove override' );
			row.dataset.resourceId = resource.id;
			row.dataset.templateId = template.id;
			row.dataset.fieldPath = targetPath;
			identity.appendChild( createElement( 'strong', '', baseField ? displayFieldLabel( baseField ) : targetPath ) );
			mapping.id = 'nova-template-override-mapping-' + safeId;
			mappingLabel.htmlFor = mapping.id;
			mapping.type = 'text';
			mapping.value = stringValue( override.mapping, '' );
			mapping.placeholder = 'Template-specific NOVA source';
			mapping.dataset.control = 'template-field-mapping';
			mapping.dataset.resourceId = resource.id;
			mapping.dataset.templateId = template.id;
			mapping.dataset.fieldPath = targetPath;
			mapping.addEventListener( 'input', function () { override.mapping = mapping.value; syncPayload(); } );
			guidance.id = 'nova-template-override-guidance-' + safeId;
			guidanceLabel.htmlFor = guidance.id;
			guidance.rows = 2;
			guidance.value = stringValue( override.description, '' );
			guidance.placeholder = 'How this field differs for this template';
			guidance.dataset.control = 'template-field-guidance';
			guidance.dataset.resourceId = resource.id;
			guidance.dataset.templateId = template.id;
			guidance.dataset.fieldPath = targetPath;
			guidance.addEventListener( 'input', function () { override.description = guidance.value; syncPayload(); } );
			remove.type = 'button';
			remove.dataset.control = 'remove-template-field';
			remove.dataset.resourceId = resource.id;
			remove.dataset.templateId = template.id;
			remove.dataset.fieldPath = targetPath;
			remove.addEventListener( 'click', function () {
				delete resource.templateConfig.items[ template.id ].fields[ targetPath ];
				syncPayload();
				state.openTemplateOverrides[ resource.id + '|' + template.id ] = true;
				renderApplication();
			} );
			row.appendChild( identity );
			var mappingWrap = createElement( 'div' );
			mappingWrap.appendChild( mappingLabel );
			mappingWrap.appendChild( mapping );
			row.appendChild( mappingWrap );
			var guidanceWrap = createElement( 'div' );
			guidanceWrap.appendChild( guidanceLabel );
			guidanceWrap.appendChild( guidance );
			row.appendChild( guidanceWrap );
			var actionWrap = createElement( 'div', 'nova-content-context__mapping-actions' );
			actionWrap.appendChild( remove );
			row.appendChild( actionWrap );
			if ( baseField ) { renderTechnicalDetails( resource, baseField, row ); }
			parent.appendChild( row );
		}

		function renderTemplateOverrides( resource, template, item, parent ) {
			var key = resource.id + '|' + template.id;
			var details = createElement( 'details', 'nova-content-context__template-overrides' );
			var summary = createElement( 'summary', '', 'Field overrides (' + String( Object.keys( item.fields ).length ) + ')' );
			details.dataset.resourceId = resource.id;
			details.dataset.templateId = template.id;
			details.dataset.groupId = 'template-overrides';
			details.open = hasOwn( state.openTemplateOverrides, key ) ? state.openTemplateOverrides[ key ] : Object.keys( item.fields ).length > 0;
			details.appendChild( summary );
			var body = createElement( 'div', 'nova-content-context__template-overrides-body' );
			Object.keys( item.fields ).sort().forEach( function ( targetPath ) {
				renderTemplateOverrideRow( resource, template, targetPath, item.fields[ targetPath ], body );
			} );
			var add = createElement( 'div', 'nova-content-context__override-add' );
			var selectId = 'nova-template-override-add-' + String( resource.domUid ) + '-' + String( template.id ).replace( /[^a-z0-9_-]/gi, '-' );
			var label = createElement( 'label', '', 'Add field override' );
			var select = createElement( 'select' );
			var button = createElement( 'button', 'button button-secondary', 'Add' );
			label.htmlFor = selectId;
			select.id = selectId;
			select.dataset.control = 'template-field-selector';
			select.dataset.resourceId = resource.id;
			select.dataset.templateId = template.id;
			var placeholder = createElement( 'option', '', 'Choose a textual API field' );
			placeholder.value = '';
			select.appendChild( placeholder );
			eligibleTemplateFields( resource ).filter( function ( field ) { return ! hasOwn( item.fields, field.path ); } ).forEach( function ( field ) {
				var option = createElement( 'option', '', displayFieldLabel( field ) + ' — ' + field.path );
				option.value = field.path;
				select.appendChild( option );
			} );
			button.type = 'button';
			button.dataset.control = 'add-template-field';
			button.dataset.resourceId = resource.id;
			button.dataset.templateId = template.id;
			button.addEventListener( 'click', function () {
				if ( ! select.value ) { select.focus(); return; }
				item.fields[ select.value ] = { description: '', mapping: '', manual: true };
				state.openTemplateOverrides[ key ] = true;
				syncPayload();
				renderApplication();
			} );
			add.appendChild( label );
			add.appendChild( select );
			add.appendChild( button );
			body.appendChild( add );
			details.appendChild( body );
			details.addEventListener( 'toggle', function () { state.openTemplateOverrides[ key ] = details.open; } );
			parent.appendChild( details );
		}

		function renderTemplatesSection( resource, parent ) {
			var templateField = resource.fields.find( function ( field ) { return field.path === '/template'; } );
			var config = normaliseTemplateSelection( resource );
			var templates = templateDescriptorsForDisplay( resource );
			if ( ! templateField && templates.length === 0 ) { return; }
			var section = createElement( 'section', 'nova-content-context__templates' );
			section.dataset.resourceId = resource.id;
			section.dataset.groupId = 'templates';
			var heading = createElement( 'div', 'nova-content-context__templates-heading' );
			heading.appendChild( createElement( 'h4', '', 'Templates' ) );
			heading.appendChild( createElement( 'p', 'description', 'Choose the templates NOVA may use and explain when each one applies.' ) );
			section.appendChild( heading );
			if ( templateField ) {
				var apiMap = createElement( 'div', 'nova-content-context__template-api-map' );
				renderMappingRow( resource, templateField, apiMap, { groupId: 'templates', label: 'Template' } );
				section.appendChild( apiMap );
			}
			if ( templates.length === 0 ) {
				section.appendChild( createElement( 'p', 'description', 'The API accepts a template value, but no templates were discovered.' ) );
				parent.appendChild( section );
				return;
			}
			var list = createElement( 'div', 'nova-content-context__template-list' );
			templates.forEach( function ( template, templateIndex ) {
				var selected = config.selected.indexOf( template.id ) !== -1;
				var item = ensureTemplateItem( config, template.id, template.slug );
				var templateKey = resource.id + '|' + template.id;
				var card = createElement( 'div', 'nova-content-context__template-item' );
				var selector = createElement( 'div', 'nova-content-context__template-selector' );
				var checkboxLabel = createElement( 'label' );
				var checkbox = createElement( 'input' );
				var checkboxId = 'nova-template-selected-' + String( resource.domUid ) + '-' + String( templateIndex );
				card.dataset.resourceId = resource.id;
				card.dataset.templateId = template.id;
				checkbox.id = checkboxId;
				checkbox.type = 'checkbox';
				checkbox.checked = selected;
				checkbox.dataset.control = 'template-selected';
				checkbox.dataset.resourceId = resource.id;
				checkbox.dataset.templateId = template.id;
				checkboxLabel.htmlFor = checkboxId;
				checkboxLabel.appendChild( checkbox );
				checkboxLabel.appendChild( document.createTextNode( ' ' + template.label ) );
				selector.appendChild( checkboxLabel );
				if ( template.availability && template.availability !== 'available' ) { appendBadge( selector, template.availability === 'saved' ? 'Saved' : 'Needs API access', template.availability === 'saved' ? 'stale' : 'potential' ); }
				card.appendChild( selector );
				var templateTechnical = createElement( 'details', 'nova-content-context__technical nova-content-context__template-technical' );
				var templateMetadata = createElement( 'dl', 'nova-content-context__technical-body' );
				var templateTechnicalKey = resource.id + '|@template|' + template.id;
				templateTechnical.dataset.resourceId = resource.id;
				templateTechnical.dataset.templateId = template.id;
				templateTechnical.dataset.control = 'template-technical-details';
				templateTechnical.open = Boolean( state.openTechnical[ templateTechnicalKey ] );
				templateTechnical.appendChild( createElement( 'summary', '', 'Technical details' ) );
				appendDefinition( templateMetadata, 'Template ID', template.id, true );
				appendDefinition( templateMetadata, 'Template slug', template.slug || '(default)', true );
				appendDefinition( templateMetadata, 'Availability', template.availability || 'available', false );
				if ( template.availabilityReason ) { appendDefinition( templateMetadata, 'Availability note', template.availabilityReason, false ); }
				if ( template.writable !== null ) { appendDefinition( templateMetadata, 'Writable', template.writable ? 'Yes' : 'No', false ); }
				templateTechnical.appendChild( templateMetadata );
				templateTechnical.addEventListener( 'toggle', function () { state.openTechnical[ templateTechnicalKey ] = templateTechnical.open; } );
				card.appendChild( templateTechnical );
				checkbox.addEventListener( 'change', function () {
					if ( checkbox.checked ) { addUniqueString( config.selected, template.id ); } else { config.selected = config.selected.filter( function ( id ) { return id !== template.id; } ); }
					normaliseTemplateSelection( resource );
					delete state.templateErrors[ templateKey ];
					state.openResources[ resource.id ] = true;
					syncPayload();
					renderApplication( checkboxId );
				} );
				if ( selected ) {
					var selectedControls = createElement( 'div', 'nova-content-context__template-selected-controls' );
					var primaryLabel = createElement( 'label', 'nova-content-context__primary-template' );
					var primary = createElement( 'input' );
					primary.type = 'radio';
					primary.name = 'nova-primary-template-' + String( resource.domUid );
					primary.checked = config.primary === template.id;
					primary.disabled = config.selected.length === 1;
					primary.dataset.control = 'template-primary';
					primary.dataset.resourceId = resource.id;
					primary.dataset.templateId = template.id;
					primary.addEventListener( 'change', function () { if ( primary.checked ) { config.primary = template.id; syncPayload(); } } );
					primaryLabel.appendChild( primary );
					primaryLabel.appendChild( document.createTextNode( config.selected.length === 1 ? ' Primary template' : ' Use as primary' ) );
					selectedControls.appendChild( primaryLabel );
					var contextId = 'nova-template-context-' + String( resource.domUid ) + '-' + String( templateIndex );
					var contextLabel = createElement( 'label', '', 'When should NOVA use this template?' + ( config.selected.length > 1 ? ' (required)' : ' (optional)' ) );
					var context = createElement( 'textarea', 'large-text' );
					contextLabel.htmlFor = contextId;
					context.id = contextId;
					context.rows = 2;
					context.value = item.description;
					context.required = config.selected.length > 1;
					context.setAttribute( 'aria-required', config.selected.length > 1 ? 'true' : 'false' );
					context.placeholder = 'For example: use for long-form knowledge-base articles.';
					context.dataset.control = 'template-context';
					context.dataset.resourceId = resource.id;
					context.dataset.templateId = template.id;
					var validationMessage = null;
					if ( state.templateErrors[ templateKey ] ) { context.setAttribute( 'aria-invalid', 'true' ); }
					context.addEventListener( 'input', function () {
						item.description = context.value;
						delete state.templateErrors[ templateKey ];
						context.removeAttribute( 'aria-invalid' );
						if ( validationMessage ) {
							validationMessage.remove();
							validationMessage = null;
						}
						syncPayload();
					} );
					selectedControls.appendChild( contextLabel );
					selectedControls.appendChild( context );
					if ( state.templateErrors[ templateKey ] ) {
						validationMessage = createElement( 'p', 'nova-content-context__validation', state.templateErrors[ templateKey ] );
						selectedControls.appendChild( validationMessage );
					}
					renderTemplateOverrides( resource, template, item, selectedControls );
					card.appendChild( selectedControls );
				}
				list.appendChild( card );
			} );
			section.appendChild( list );
			parent.appendChild( section );
		}

		function extractBridgeFields( response ) {
			if ( ! isObject( response ) ) {
				return [];
			}
			if ( hasOwn( response, 'fields' ) ) {
				return normaliseFieldCollection( response.fields );
			}
			if ( isObject( response.data ) && hasOwn( response.data, 'fields' ) ) {
				return normaliseFieldCollection( response.data.fields );
			}
			if ( isObject( response.resource ) && hasOwn( response.resource, 'fields' ) ) {
				return normaliseFieldCollection( response.resource.fields );
			}
			return [];
		}

		function mergeBridgeFields( resource, rawFields, postId, response ) {
			var count = 0;
			rawFields.forEach( function ( rawField, index ) {
				var source = isObject( rawField ) ? Object.assign( {}, rawField ) : {};
				source.source = source.source || 'builder';
				source.group = source.group || 'builder';
				source.transport = source.transport || 'nova_builder';
				source.source_post_id = source.source_post_id || postId;
				source.source_post_title = source.source_post_title || firstString( response, [ 'post_title', 'title' ], '' );
				var incoming = makeField( source, '/@builders/field-' + String( index + 1 ) );
				var existingIndex = resource.fields.findIndex( function ( field ) { return field.path === incoming.path; } );
				if ( existingIndex !== -1 ) {
					var existing = resource.fields[ existingIndex ];
					incoming.uid = existing.uid;
					incoming.customDescription = existing.customDescription || incoming.customDescription;
					incoming.mapping = existing.mapping || incoming.mapping;
					incoming.manual = existing.manual;
					incoming.stale = false;
					resource.fields[ existingIndex ] = incoming;
				} else {
					resource.fields.push( incoming );
				}
				count += 1;
			} );
			return count;
		}

		async function loadBridgeFields( resource, postId ) {
			if ( ! resource.bridgeFieldsUrl ) {
				throw new Error( 'The bridge inspector URL is not available.' );
			}
			var requestUrl = new window.URL( resource.bridgeFieldsUrl, window.location.href );
			requestUrl.searchParams.set( 'post_id', String( postId ) );
			var headers = { Accept: 'application/json' };
			if ( state.settings.nonce ) {
				headers[ 'X-WP-Nonce' ] = stringValue( state.settings.nonce, '' );
			}
			var response = await window.fetch( requestUrl.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers } );
			var text = await response.text();
			var data = {};
			if ( text ) {
				try {
					data = JSON.parse( text );
				} catch ( error ) {
					throw new Error( 'The bridge returned invalid JSON.' );
				}
			}
			if ( ! response.ok ) {
				throw new Error( firstString( data, [ 'message', 'error' ], 'Bridge inspection failed (HTTP ' + String( response.status ) + ').' ) );
			}
			var responsePostType = firstString( data, [ 'post_type' ], isObject( data.data ) ? firstString( data.data, [ 'post_type' ], '' ) : '' );
			if ( responsePostType && resource.postType && responsePostType !== resource.postType ) {
				throw new Error( 'That post belongs to a different content type.' );
			}
			return { data: data, fields: extractBridgeFields( data ) };
		}

		function renderBuilderInspector( resource, parent ) {
			if ( resource.type !== 'post_type' || resource.bridgeExamples.length === 0 || ! resource.bridgeFieldsUrl ) {
				return;
			}
			var section = createElement( 'details', 'nova-content-context__builder-inspector' );
			var body = createElement( 'div', 'nova-content-context__builder-inspector-body' );
			var controls = createElement( 'div', 'nova-content-context__builder-controls' );
			var selectGroup = createElement( 'div', 'nova-content-context__field-control' );
			var idGroup = createElement( 'div', 'nova-content-context__field-control nova-content-context__builder-id' );
			var selectId = 'nova-builder-example-' + String( resource.domUid );
			var inputId = 'nova-builder-post-id-' + String( resource.domUid );
			var statusId = 'nova-builder-status-' + String( resource.domUid );
			var selectLabel = createElement( 'label', '', 'Example document' );
			var select = createElement( 'select' );
			var inputLabel = createElement( 'label', '', 'Post ID' );
			var input = createElement( 'input', 'small-text' );
			var button = createElement( 'button', 'button button-secondary', state.bridgeLoading[ resource.id ] ? 'Loading…' : 'Load bridge fields' );
			var status = createElement( 'p', 'nova-content-context__builder-status' );
			section.dataset.resourceId = resource.id;
			section.dataset.groupId = 'builder-inspector';
			section.open = Boolean( state.openBuilders[ resource.id ] );
			section.appendChild( createElement( 'summary', '', 'Inspect a page-builder document' ) );
			body.appendChild( createElement( 'p', 'description', 'Load the exact textual elements exposed by NOVA\'s bridge for an existing document.' ) );
			selectLabel.htmlFor = selectId;
			select.id = selectId;
			select.dataset.control = 'builder-example';
			select.dataset.resourceId = resource.id;
			var placeholder = createElement( 'option', '', 'Choose an example' );
			placeholder.value = '';
			select.appendChild( placeholder );
			resource.bridgeExamples.forEach( function ( example ) {
				var option = createElement( 'option', '', example.label + ' (#' + String( example.postId ) + ')' + ( example.builder ? ' — ' + example.builder : '' ) );
				option.value = String( example.postId );
				select.appendChild( option );
			} );
			select.addEventListener( 'change', function () { if ( select.value ) { input.value = select.value; } } );
			selectGroup.appendChild( selectLabel );
			selectGroup.appendChild( select );
			inputLabel.htmlFor = inputId;
			input.id = inputId;
			input.type = 'number';
			input.dataset.control = 'builder-post-id';
			input.dataset.resourceId = resource.id;
			input.min = '1';
			input.step = '1';
			input.inputMode = 'numeric';
			input.setAttribute( 'aria-describedby', statusId );
			idGroup.appendChild( inputLabel );
			idGroup.appendChild( input );
			button.type = 'button';
			button.dataset.control = 'load-builder-fields';
			button.dataset.resourceId = resource.id;
			button.disabled = Boolean( state.bridgeLoading[ resource.id ] );
			button.addEventListener( 'click', async function () {
				var postId = parseInt( input.value, 10 );
				if ( ! postId || postId < 1 ) {
					status.textContent = 'Choose an example or enter a valid post ID.';
					status.className = 'nova-content-context__builder-status nova-content-context__builder-status--error';
					input.focus();
					return;
				}
				state.bridgeLoading[ resource.id ] = true;
				button.disabled = true;
				button.textContent = 'Loading…';
				status.textContent = 'Inspecting post #' + String( postId ) + '…';
				try {
					var result = await loadBridgeFields( resource, postId );
					var count = mergeBridgeFields( resource, result.fields, postId, result.data );
					state.bridgeStatus[ resource.id ] = { type: 'success', text: count ? 'Loaded ' + String( count ) + ' bridge fields from post #' + String( postId ) + '.' : 'The bridge found no textual fields in post #' + String( postId ) + '.' };
					state.openGroups[ resource.id + '|builder' ] = true;
					syncPayload();
					state.openResources[ resource.id ] = true;
				} catch ( error ) {
					state.bridgeStatus[ resource.id ] = { type: 'error', text: error && error.message ? error.message : 'Bridge inspection failed.' };
				} finally {
					state.bridgeLoading[ resource.id ] = false;
					renderApplication( inputId );
				}
			} );
			controls.appendChild( selectGroup );
			controls.appendChild( idGroup );
			controls.appendChild( button );
			body.appendChild( controls );
			status.id = statusId;
			status.setAttribute( 'role', 'status' );
			if ( state.bridgeStatus[ resource.id ] ) {
				status.textContent = state.bridgeStatus[ resource.id ].text;
				status.className += ' nova-content-context__builder-status--' + state.bridgeStatus[ resource.id ].type;
			}
			body.appendChild( status );
			section.appendChild( body );
			section.addEventListener( 'toggle', function () { state.openBuilders[ resource.id ] = section.open; } );
			parent.appendChild( section );
		}

		function renderManualFieldControl( resource, parent ) {
			var details = createElement( 'details', 'nova-content-context__manual' );
			var controls = createElement( 'div', 'nova-content-context__manual-controls' );
			var inputId = 'nova-manual-field-' + String( resource.domUid );
			var errorId = inputId + '-error';
			var label = createElement( 'label', '', 'JSON Pointer path' );
			var input = createElement( 'input', 'regular-text' );
			var button = createElement( 'button', 'button button-secondary', 'Add field' );
			var error = createElement( 'p', 'nova-content-context__validation' );
			details.dataset.resourceId = resource.id;
			details.dataset.groupId = 'manual-field';
			details.appendChild( createElement( 'summary', '', 'Add a field that discovery missed' ) );
			details.appendChild( createElement( 'p', 'description', 'Use this only for a known request field that is absent from discovery.' ) );
			label.htmlFor = inputId;
			input.id = inputId;
			input.type = 'text';
			input.placeholder = '/meta/subtitle';
			input.dataset.control = 'manual-field-path';
			input.dataset.resourceId = resource.id;
			input.setAttribute( 'aria-describedby', errorId );
			button.type = 'button';
			button.dataset.control = 'add-manual-field';
			button.dataset.resourceId = resource.id;
			error.id = errorId;
			error.setAttribute( 'role', 'alert' );
			button.addEventListener( 'click', function () {
				var path = input.value.trim();
				if ( path === '' || path.charAt( 0 ) !== '/' ) {
					error.textContent = 'Enter a JSON Pointer beginning with "/".';
					input.setAttribute( 'aria-invalid', 'true' );
					input.focus();
					return;
				}
				var existing = resource.fields.find( function ( field ) { return field.path === path; } );
				if ( existing ) {
					existing.manual = true;
					existing.configurable = true;
					state.openResources[ resource.id ] = true;
					state.openGroups[ resource.id + '|' + fieldGroup( existing ) ] = true;
					syncPayload();
					renderApplication( 'nova-content-context-field-' + String( existing.uid ) );
					return;
				}
				var field = makeField( { path: path, label: path, source: 'other', transport: 'unknown', request_path: path, manual: true }, path );
				resource.fields.push( field );
				state.openResources[ resource.id ] = true;
				state.openGroups[ resource.id + '|other' ] = true;
				syncPayload();
				renderApplication( 'nova-content-context-field-' + String( field.uid ) );
			} );
			input.addEventListener( 'input', function () { error.textContent = ''; input.removeAttribute( 'aria-invalid' ); } );
			input.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Enter' ) { event.preventDefault(); button.click(); } } );
			details.appendChild( label );
			controls.appendChild( input );
			controls.appendChild( button );
			details.appendChild( controls );
			details.appendChild( error );
			parent.appendChild( details );
		}

		function renderEndpointSelection( resource, parent, selectionBadge ) {
			var container = createElement( 'div', 'nova-content-context__endpoint-selection' );
			var label = createElement( 'label' );
			var checkbox = createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.checked = Boolean( resource.enabled );
			checkbox.dataset.control = 'resource-enabled';
			checkbox.dataset.resourceId = resource.id;
			container.dataset.resourceId = resource.id;
			checkbox.addEventListener( 'change', function () {
				resource.enabled = checkbox.checked;
				resource.enabledExplicit = true;
				syncPayload();
				selectionBadge.textContent = resource.enabled ? 'Enabled for NOVA' : 'Not enabled';
				selectionBadge.className = 'nova-content-context__badge nova-content-context__badge--' + ( resource.enabled ? 'manual' : 'neutral' );
			} );
			label.appendChild( checkbox );
			label.appendChild( document.createTextNode( ' Include as a NOVA publishing destination' ) );
			container.appendChild( label );
			container.appendChild( createElement( 'p', 'description', resourceAvailability( resource ) === 'usable' ? 'NOVA may publish to this endpoint.' : 'The selection and mapping can be prepared now, but publishing requires API access.' ) );
			parent.appendChild( container );
		}

		function renderResource( resource, parent ) {
			var availability = resourceAvailability( resource );
			var availableFields = resource.fields.filter( function ( field ) { return field.path !== '/template' && fieldGroup( field ) !== 'template' && fieldAvailability( field ) === 'available'; } ).length;
			var potentialFields = resource.fields.filter( function ( field ) { return field.path !== '/template' && fieldGroup( field ) !== 'template' && fieldAvailability( field ) === 'potential'; } ).length;
			var templateContexts = ( resource.templates || [] ).length;
			var details = createElement( 'details', 'nova-content-context__resource nova-content-context__resource--' + availability );
			var summary = createElement( 'summary', 'nova-content-context__summary' );
			var identity = createElement( 'span', 'nova-content-context__resource-identity' );
			var badges = createElement( 'span', 'nova-content-context__badges' );
			var body = createElement( 'div', 'nova-content-context__resource-body' );
			var bodyRendered = false;
			details.dataset.resourceId = resource.id;
			details.dataset.resourceType = resource.type;
			identity.appendChild( createElement( 'span', 'nova-content-context__resource-title', resource.label ) );
			identity.appendChild( createElement( 'code', 'nova-content-context__resource-route', resource.postType || resource.taxonomy || resource.id ) );
			summary.appendChild( identity );
			appendBadge( badges, availability === 'usable' ? 'Usable' : 'Needs API access', availability );
			appendBadge( badges, String( availableFields ) + ' available', 'neutral' );
			if ( templateContexts ) {
				appendBadge( badges, String( templateContexts ) + ' templates', 'manual' );
			}
			if ( potentialFields ) {
				appendBadge( badges, String( potentialFields ) + ' could be exposed', 'potential' );
			}
			var selectionBadge = appendBadge( badges, resource.enabled ? 'Enabled for NOVA' : 'Not enabled', resource.enabled ? 'manual' : 'neutral' );
			summary.appendChild( badges );
			details.appendChild( summary );
			details.open = Boolean( state.openResources[ resource.id ] || state.selectedResource === resource.id );
			function renderBody() {
				if ( bodyRendered ) {
					return;
				}
				bodyRendered = true;
				renderEndpointSelection( resource, body, selectionBadge );
				if ( resource.type === 'post_type' && resource.showInRest === false ) {
					var restNotice = createElement( 'div', 'notice notice-error inline nova-content-context__notice', SHOW_IN_REST_WARNING );
					restNotice.setAttribute( 'role', 'note' );
					body.appendChild( restNotice );
				} else if ( availability === 'unavailable' ) {
					var message = resource.message || resource.reason.replace( /[_-]+/g, ' ' ) || 'This endpoint is not currently writable through the API.';
					var notice = createElement( 'div', 'notice notice-error inline nova-content-context__notice', message );
					notice.setAttribute( 'role', 'note' );
					body.appendChild( notice );
				}
				renderTemplatesSection( resource, body );
				var endpointKey = resource.id + '|@endpoint';
				var endpointTechnical = createElement( 'details', 'nova-content-context__endpoint-technical' );
				endpointTechnical.dataset.resourceId = resource.id;
				endpointTechnical.dataset.groupId = 'endpoint-technical';
				endpointTechnical.dataset.control = 'endpoint-technical-details';
				endpointTechnical.open = Boolean( state.openTechnical[ endpointKey ] );
				endpointTechnical.appendChild( createElement( 'summary', '', 'Technical endpoint details' ) );
				var metadata = createElement( 'dl', 'nova-content-context__metadata' );
				appendDefinition( metadata, 'Type', resource.type === 'taxonomy' ? 'Taxonomy' : 'Post type', false );
				appendDefinition( metadata, 'Identifier', resource.postType || resource.taxonomy || resource.id, true );
				appendDefinition( metadata, 'REST route', resource.route || resource.expectedRoute, true );
				appendDefinition( metadata, 'Write methods', resource.methods.join( ', ' ), false );
				endpointTechnical.appendChild( metadata );
				endpointTechnical.addEventListener( 'toggle', function () { state.openTechnical[ endpointKey ] = endpointTechnical.open; } );
				body.appendChild( endpointTechnical );
				renderBuilderInspector( resource, body );
				renderFieldGroups( resource, body );
				renderManualFieldControl( resource, body );
				details.appendChild( body );
			}
			details.addEventListener( 'toggle', function () { state.openResources[ resource.id ] = details.open; if ( details.open ) { renderBody(); } } );
			if ( details.open ) {
				renderBody();
			}
			parent.appendChild( details );
		}

		function renderResourceResults() {
			var oldCount = root.querySelector( '.nova-content-context__result-count' );
			var oldResources = root.querySelector( '.nova-content-context__resources' );
			if ( oldCount ) { oldCount.remove(); }
			if ( oldResources ) { oldResources.remove(); }
			var filtered = state.resources.filter( function ( resource ) {
				return resourceMatches( resource, state.query, state.filter, state.selectedResource );
			} ).sort( function ( left, right ) {
				return left.label.localeCompare( right.label, undefined, { sensitivity: 'base' } );
			} );
			var count = createElement( 'p', 'nova-content-context__result-count', String( filtered.length ) + ' of ' + String( state.resources.length ) + ' endpoints shown' );
			count.dataset.control = 'resource-result-count';
			root.appendChild( count );
			var resources = createElement( 'div', 'nova-content-context__resources' );
			if ( filtered.length === 0 ) {
				resources.appendChild( createElement( 'div', 'nova-content-context__state nova-content-context__state--empty', 'No endpoints match this search and filter.' ) );
			} else {
				filtered.forEach( function ( resource ) { renderResource( resource, resources ); } );
			}
			root.appendChild( resources );
		}

		function renderApplication( focusId ) {
			if ( state.searchTimer ) { window.clearTimeout( state.searchTimer ); state.searchTimer = null; }
			clearElement( root );
			root.removeAttribute( 'aria-busy' );
			renderHeader( root );
			if ( state.resources.length === 0 ) {
				root.appendChild( createElement( 'div', 'notice notice-info nova-content-context__state nova-content-context__state--empty', 'No relevant publishing endpoints were discovered.' ) );
				return;
			}
			renderToolbar( root );
			renderResourceResults();
			if ( focusId ) {
				window.requestAnimationFrame( function () {
					var target = document.getElementById( focusId );
					if ( target ) { target.focus(); }
				} );
			}
		}

		async function loadDiscovery() {
			var url = stringValue( state.settings.discoveryUrl, '' );
			if ( ! url ) {
				renderError( 'The endpoint discovery URL is not configured.' );
				return;
			}
			state.requestNumber += 1;
			var requestNumber = state.requestNumber;
			renderLoading();
			try {
				var headers = { Accept: 'application/json' };
				if ( state.settings.nonce ) { headers[ 'X-WP-Nonce' ] = stringValue( state.settings.nonce, '' ); }
				var response = await window.fetch( url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers } );
				var text = await response.text();
				var data = {};
				if ( text ) {
					try { data = JSON.parse( text ); } catch ( error ) { throw new Error( 'Endpoint discovery returned invalid JSON.' ); }
				}
				if ( ! response.ok ) {
					throw new Error( firstString( data, [ 'message', 'error' ], 'Endpoint discovery failed (HTTP ' + String( response.status ) + ').' ) );
				}
				if ( requestNumber !== state.requestNumber ) { return; }
				var discovered = extractResourceCollection( data ).filter( function ( resource ) {
					var type = firstString( resource, [ 'type', 'resource_type', 'kind' ], '' );
					return type === 'post_type' || type === 'taxonomy';
				} ).map( makeResource );
				state.resources = mergeSavedConfig( discovered, state.savedConfig );
				syncPayload();
				renderApplication();
			} catch ( error ) {
				if ( requestNumber === state.requestNumber ) {
					renderError( error && error.message ? error.message : 'Endpoint discovery failed.' );
				}
			}
		}

		function handleFormSubmit( event ) {
			var firstInvalid = null;
			state.templateErrors = Object.create( null );
			state.resources.forEach( function ( resource ) {
				var config = normaliseTemplateSelection( resource );
				if ( config.selected.length < 2 ) { return; }
				config.selected.forEach( function ( templateId ) {
					var item = ensureTemplateItem( config, templateId, templateId === 'default' ? '' : templateId );
					if ( item && ! stringValue( item.description, '' ).trim() ) {
						state.templateErrors[ resource.id + '|' + templateId ] = 'Explain when NOVA should use this template.';
						if ( ! firstInvalid ) { firstInvalid = { resource: resource, templateId: templateId }; }
					}
				} );
			} );
			if ( firstInvalid ) {
				event.preventDefault();
				state.selectedResource = firstInvalid.resource.id;
				state.filter = 'all';
				state.openResources[ firstInvalid.resource.id ] = true;
				var templateIndex = templateDescriptorsForDisplay( firstInvalid.resource ).findIndex( function ( template ) { return template.id === firstInvalid.templateId; } );
				renderApplication( 'nova-template-context-' + String( firstInvalid.resource.domUid ) + '-' + String( Math.max( templateIndex, 0 ) ) );
				return;
			}
			syncPayload();
		}

		var form = payload.form || root.closest( 'form' );
		if ( form ) { form.addEventListener( 'submit', handleFormSubmit ); }
		syncPayload();
		loadDiscovery();
	}

	onReady( initialise );
}() );
