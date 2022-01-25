<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('reolink');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i>  {{Gestion}}</legend>
		<!-- Boutons de gestion du plugin -->
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-table"></i> {{Mes caméras}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<br/><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucune caméra Reolink n\'est paramétré, cliquer sur "Ajouter" pour commencer}}</div>';
		} else {
			// Champ de recherche
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic"/>';
			echo '<div class="input-group-btn">';
			echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
			echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
			echo '</div>';
			echo '</div>';
			// Liste des équipements du plugin
			echo '<div class="eqLogicThumbnailContainer">';
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor '.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $plugin->getPathImgIcon() . '"/>';
				echo '<br>';
				echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		?>
	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de présentation de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<!-- barre de gestion de l'équipement -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs">  {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
		<!--	<li role="presentation"><a href="#ability" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-info-circle"></i> {{Informations}}</a></li>-->
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<!-- Partie gauche de l'onglet "Equipements" -->
				<!-- Paramètres généraux de l'équipement -->
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;"/>
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label" >{{Objet parent}}</label>
								<div class="col-sm-7">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-7">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Options}}</label>
								<div class="col-sm-7">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
								</div>
							</div>
							<legend><i class="fas fa-cogs"></i> {{Paramètres d'accès à la caméra}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse IP de la caméra}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="adresseip"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le port de la caméra uniquement si vous l'avez modifier. Sinon laisser ce champ vide}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="{{(facultatif)}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Login}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le login de la caméras, celui utiliser pour accéder à l'interface web}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="login"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Mot de passe}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le mot de passe}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control inputPassword" data-l1key="configuration" data-l2key="password"/>
								</div>
							</div>
							<div class="form-group">
									<label class="col-sm-3 control-label">{{Type de connexion}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Type de connexion pour se connecter à la caméra, attention HTTPS doit être activé sur la caméra pour fonctionner}}"></i></sup></label>
									<div class="col-sm-3">
											<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cnxtype">
													<option value="http">{{HTTP}}</option>
													<option value="https">{{HTTPS}}</option>
											</select>
									</div>
							</div>
							<div class="form-group expertModeVisible">
								<label class="col-sm-3 control-label">{{Auto-actualisation (cron)}}</label>
									<div class="col-sm-3">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="autorefresh" placeholder="*/15 * * * *"/>
									</div>
									<div class="col-sm-1">
										<i class="fas fa-question-circle cursor floatright" id="bt_cronGenerator"></i>
									</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{}}</label>
								<div class="col-sm-3">
									<a class="btn btn-default" id="btCheckConnexion"><i class='fa fa-refresh'></i> {{Tester la connexion}}</a>
								</div>
							</div>
						</div>

						<!-- Partie droite de l'onglet "Équipement" -->
						<!-- Affiche l'icône du plugin par défaut mais vous pouvez y afficher les informations de votre choix -->
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<div class="text-center">
									<img name="icon_visu" src="<?php echo $plugin->getPathImgIcon(); ?>" style="max-width:160px;"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Nom de la caméra}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="name"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Modèle}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="model"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Channel}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="channelNum"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{UID}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="serial"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Build No}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="buildDay"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version hardware}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="hardVer"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Version config}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="cfgVer"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"> {{Version firmware}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="firmVer"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Détail}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="detail"></span>
								</div>
							</div>
							<hr>
							<div class="container-fluid">
									<div class="form-group">
											<a class="btn btn-block btn-default eqLogicAction" id="btGetCamNFO"><i class="fas fa-download"></i> {{Récupérer les informations}}</a>
									</div>
									<br>
							</div>
						</div>
					</fieldset>
				</form>
				<hr>
			</div><!-- /.tabpanel #eqlogictab-->
			<div role="tabpanel" class="tab-pane" id="ability">
				<div class="col-lg-7" style="padding:10px 35px">
						<legend>
								<span>{{Capacité Hardware/Software de la caméra}}</span>
						</legend>
						<br />
						<div class="container" style="width: 90%;">
								<div class="form-group">
										<!-- table  -->
										<table class="table table-bordered table-condensed" style="text-align:center">
												<tbody>
														<tr>
														</tr>
												</tbody>
										</table>
								</div>
						</div>
				</div>


				<div class="col-lg-5" style="padding:15px 35px">
						<legend>
								<span style="text-align:left">{{Action}}</span>
						</legend>
				</div>
			</div>

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<!--<a class="btn btn-default btn-sm pull-right cmdAction" data-action="add" style="margin-top:5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une commande}}</a>-->
				<br/><br/>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Id}}</th>
								<th>{{Nom}}</th>
								<th>{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th>{{Options}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<script>
$('#btCheckConnexion').on('click', function () {
		$.ajax({// fonction permettant de faire de l'ajax
				type: "POST", // methode de transmission des données au fichier php
				url: "plugins/reolink/core/ajax/reolink.ajax.php", // url du fichier php
				data: {
					action: "CheckConnexion",
					id : $('.eqLogicAttr[data-l1key=id]').value()
				},
				dataType: 'json',
				error: function (request, status, error) {
					handleAjaxError(request, status, error);
				},
				success: function (data) { // si l'appel a bien fonctionné
				if (data.state != 'ok') {
					$('#div_alert').showAlert({message: data.result, level: 'danger'});
					return;
				}
				$('#div_alert').showAlert({message: '{{Connexion à la caméra réussie}}', level: 'success'});
			}
		});
});

$('#btGetCamNFO').on('click', function () {
		$.ajax({// fonction permettant de faire de l'ajax
				type: "POST", // methode de transmission des données au fichier php
				url: "plugins/reolink/core/ajax/reolink.ajax.php", // url du fichier php
				data: {
					action: "CheckDeviceInfo",
					id : $('.eqLogicAttr[data-l1key=id]').value()
				},
				dataType: 'json',
				error: function (request, status, error) {
					handleAjaxError(request, status, error);
				},
				success: function (data) { // si l'appel a bien fonctionné
				if (data.state != 'ok') {
					$('#div_alert').showAlert({message: data.result, level: 'danger'});
					return;
				}
				$('#div_alert').showAlert({message: '{{Information récupérer avec succès}}', level: 'success'});
				window.location.reload();
			}
		});
});
</script>
<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'reolink', 'js', 'reolink');?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js');?>
