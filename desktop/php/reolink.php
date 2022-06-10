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
		<!--<li role="presentation"><a href="#mailnotif" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-envelope-open-text"></i><span class="hidden-xs"> {{Email}}</span></a></li>
			<li role="presentation"><a href="#ftpsend" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-file-upload"></i><span class="hidden-xs"> {{FTP}}</span></a></li>-->
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
								<label class="col-sm-3 control-label">{{IP / Nom d'hôte}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez l'adresse IP ou le nom d'hôte de la caméra}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="adresseip"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port HTTP/HTTPS}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le port de l'interface web de la caméra uniquement si vous l'avez modifier. Sinon laisser ce champ vide}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="{{(facultatif)}}"/>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port ONVIF}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le port du protocole ONVIF uniquement si vous l'avez modifier. Sinon laisser ce champ vide}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port_onvif" placeholder="{{8000}}"/>
								</div>
							</div>
							<!--- NOT USED
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Channel}}
									<sup><i class="fas fa-question-circle tooltips" title="{{Renseignez le numéro de channel (uniquement si la caméra est utilisé via un NVR)}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="defined_channel" placeholder="{{(facultatif)}}"/>
								</div>
							</div>
							-->
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
									<img name="icon_visu" id="icon_visu" src= "<?php echo $plugin->getPathImgIcon(); ?>" style="max-width:160px;"/>
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
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="uid"></span>
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
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Connectivité}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="linkconnection"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Caméra IA}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="supportai"></span>
								</div>
							</div>

							<hr>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{HTTP Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="httpPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="httpEnable"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{HTTPS Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="httpsPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="httpsEnable"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Media Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="mediaPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="mediaEnable"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{ONVIF Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="onvifPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="onvifEnable"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{RTMP Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="rtmpPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="rtmpEnable"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{RTSP Port}}</label>
								<div class="col-sm-7">
									<span class="eqLogicAttr" data-l1key="configuration" data-l2key="rtspPort"></span>&nbsp;<span class="eqLogicAttr" data-l1key="configuration" data-l2key="rtspEnable"></span>
								</div>
						</div>
						<hr>
							<div class="container-fluid">
									<div class="form-group">
											<a class="btn btn-block btn-primary eqLogicAction" id="btGetCamNFO"><i class="fas fa-download"></i> {{Récupérer les informations}}</a>
									</div>
									<br>
							</div>
							<div class="container-fluid">
									<div class="form-group">
											<a class="btn btn-block btn-default eqLogicAction" id="btGetCMD"><i class="fas fa-plus-circle"></i> {{Créer (recréer) les commandes}}</a>
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
			<!--
			MAIL Notif
			--->
						<div role="tabpanel" class="tab-pane" id="mailnotif">
							<div class="col-lg-7" style="padding:10px 35px">
									<legend>
											<span>{{Réglage des notifications email}}</span>
									</legend>
									<br />
									<div class="container" style="width: 90%;">
											<div class="form-group">
													<!-- table  -->
													<legend>
															<span>{{Serveur SMTP}}</span>
													</legend>
													<table class="table table-bordered table-condensed" style="text-align:center">
															<tbody>
																<tr style="height: 50px !important;">
																		<td><label class="control-label">{{Etat}}</label>&nbsp;(l'activation/désactivation s'effectue par la commande "Envoi email")</td>
																		<td>
																			<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_state" disabled>
																				<option value="0">Désactivé</option>
																				<option value="1">Activé</option>
																			</select>
																		</td>
																</tr>
																	<tr>
																			<td><label class="control-label">{{Serveur}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_server" placeholder="{{smtp.free.fr}}"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label">{{Port}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_port" placeholder="{{25}}"/></td>
																	</tr>
																	<tr style="height: 50px !important;">
																			<td><label class="control-label ">{{Utiliser SSL/TLS}}</label></td>
																			<td><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="smtp_usessltls" /></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Login}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_login"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Mot de passe}}</label></td>
																			<td><input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_password"/></td>
																	</tr>
																</tbody>
														</table>
														<!-- table  -->
														<legend>
																<span>{{Paramètre email}}</span>
														</legend>
														<table class="table table-bordered table-condensed" style="text-align:center">
																<tbody>
																	<tr>
																			<td><label class="control-label ">{{Expéditeur (email)}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mailfrom_addr"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Expéditeur (nom/prénom)}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mailfrom_name"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Sujet}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mail_subject" placeholder="{{Nouvel évènement}}"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Destinataire n°1}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mailto_addr1"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Destinataire n°2}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mailto_addr2"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Destinataire n°3}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mailto_addr3"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Fichier joint}}</label></td>
																			<td>
																					<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_attachement">
																							<option value="no">Aucun (texte seul)</option>
																							<option value="onlyPicture">Image</option>
																							<option value="picture">Image (avec texte)</option>
																							<option value="video">Vidéo (avec texte)</option>
																					</select>
																			</td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Intervale}}</label></td>
																			<td>
																					<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="smtp_interval">
																							<option value="30 Seconds">30 Seconds</option>
																							<option value="1 Minute">1 Minute</option>
																							<option value="5 Minutes">5 Minutes</option>
																							<option value="10 Minutes">10 Minutes</option>
																							<option value="30 Minutes">30 Minutes</option>
																					</select>
																			</td>
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
									<div class="container-fluid">
											<div class="form-group">
													<a  id="btGetEMAIL" onclick="GetCAMinfo('EMAIL')" class="btn btn-block btn-primary eqLogicAction"><i class="fas fa-download"></i> {{Lire la configuration depuis la caméra}}</a>
											</div>
											<br>
									</div>
									<div class="container-fluid">
											<div class="form-group">
													<a  id="btSetEMAIL" onclick="SetCAMconfig('EMAIL')" class="btn btn-block btn-success eqLogicAction"><i class="fas fa-upload"></i> {{Envoyer la configuration dans la caméra}}</a>
													(Cliquez sur "Sauvegarder" avant d'envoyer la config)
											</div>
											<br>
									</div>
							</div>
						</div>
						<!--
						FTP Send
						--->
						<div role="tabpanel" class="tab-pane" id="ftpsend">
							<div class="col-lg-7" style="padding:10px 35px">
									<legend>
											<span>{{Réglage du FTP}}</span>
									</legend>
									<br />
									<div class="container" style="width: 90%;">
											<div class="form-group">
												<legend>
														<span>{{Serveur FTP}}</span>
												</legend>
													<table class="table table-bordered table-condensed" style="text-align:center">
															<tbody>
																	<tr style="height: 50px !important;">
																			<td><label class="control-label">{{Etat}}</label>&nbsp;(l'activation/désactivation s'effectue par la commande "Envoi FTP")</td>
																			<td>
																				<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_state" disabled>
																					<option value="0">Désactivé</option>
																					<option value="1">Activé</option>
																				</select>
																			</td>
																	</tr>
																	<tr>
																			<td><label class="control-label">{{Serveur}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_server"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label">{{Anonyme}}</label></td>
																			<td><input type="checkbox" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_anonymous"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label">{{Login}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_account"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Mot de passe}}</label></td>
																			<td><input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_passwd"/></td>
																	</tr>
																	<tr style="height: 50px !important;">
																			<td><label class="control-label ">{{Utiliser SSL/TLS}}</label></td>
																			<td><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="ftp_usessltls" /></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Port}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_port" placeholder="{{21}}"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Mode}}</label></td>
																				<td>
																					<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_mode">
																						<option value="0">AUTO</option>
																						<option value="1">PORT</option>
																						<option value="2">PASV</option>
																					</select>
																				</td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Chemin distant d'upload}}</label></td>
																			<td><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_path"/></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Taille maximale de fichier à envoyer (en MB)}}</label></td>
																			<td><input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="ftp_maxfilesize" /></td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Type de fichier à envoyer}}</label></td>
																			<td>
																					<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_filetosend">
																							<option value="0">Image et vidéo (Qualité haute)</option>
																							<option value="1">Image et vidéo (Qualité moyenne)</option>
																							<option value="2">Image et vidéo (Qualité basse)</option>
																							<option value="3">Image seulement</option>
																					</select>
																			</td>
																	</tr>
																	<tr>
																			<td><label class="control-label ">{{Intervale}}</label></td>
																			<td>
																					<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ftp_interval">
																							<option value="30">30 Seconds</option>
																							<option value="60">1 Minute</option>
																							<option value="300">5 Minutes</option>
																							<option value="600">10 Minutes</option>
																							<option value="1800">30 Minutes</option>
																					</select>
																			</td>
																	</tr>
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
									<div class="container-fluid">
											<div class="form-group">
													<a  id="btGetFTP" onclick="GetCAMinfo('FTP')" class="btn btn-block btn-primary eqLogicAction"><i class="fas fa-download"></i> {{Lire la configuration depuis la caméra}}</a>
											</div>
											<br>
									</div>
									<div class="container-fluid">
											<div class="form-group">
													<a  id="btSetFTP" onclick="SetCAMconfig('FTP')" class="btn btn-block btn-success eqLogicAction"><i class="fas fa-upload"></i> {{Envoyer la configuration dans la caméra}}</a>
													(Cliquez sur "Sauvegarder" avant d'envoyer la config)
											</div>
											<br>
									</div>
							</div>
						</div>

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<script>


$('#btGetCMD').on('click', function () {
		$.ajax({// fonction permettant de faire de l'ajax
				type: "POST", // methode de transmission des données au fichier php
				url: "plugins/reolink/core/ajax/reolink.ajax.php", // url du fichier php
				data: {
					action: "CreateCMD",
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
				$('#div_alert').showAlert({message: '{{Commandes créer avec succès}}', level: 'success'});
				window.location.reload();
			}
		});
});


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
/* Actions des boutons sur la page */
function SetCAMconfig(REQgroup){
	$.ajax({// fonction permettant de faire de l'ajax
			type: "POST", // methode de transmission des données au fichier php
			url: "plugins/reolink/core/ajax/reolink.ajax.php", // url du fichier php
			data: {
				action: "SetCAMConfig",
				group:REQgroup,
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
			$('#div_alert').showAlert({message: '{{OK configuration envoyé avec succès}}', level: 'success'});
			//window.location.reload();
		}
	});
}
/* Actions des boutons sur la page */
function GetCAMinfo(REQgroup){
	$.ajax({// fonction permettant de faire de l'ajax
			type: "POST", // methode de transmission des données au fichier php
			url: "plugins/reolink/core/ajax/reolink.ajax.php", // url du fichier php
			data: {
				action: "GetCAMConfig",
				group:REQgroup,
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
			$('#div_alert').showAlert({message: '{{Lecture OK}}', level: 'success'});
			window.location.reload();
		}
	});
}
</script>
<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'reolink', 'js', 'reolink');?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js');?>
