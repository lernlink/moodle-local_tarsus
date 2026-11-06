<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German strings for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'API-Schlüssel';
$string['apikey_desc'] = 'Der Tarsus-API-Schlüssel. Wird websiteweit gespeichert und nie an den Browser gesendet.';
$string['baseurl'] = 'Basis-URL';
$string['baseurl_desc'] = 'Die Basis-URL der Tarsus-API (Datenstandort: Deutschland).';
$string['blocked_breaker'] = 'Ausgehender Aufruf blockiert: Der Schutzschalter ist offen.';
$string['blocked_budget'] = 'Ausgehender Aufruf blockiert: Das Credit-Budget ist aufgebraucht.';
$string['blocked_killswitch'] = 'Ausgehender Aufruf blockiert: Der Not-Aus ist aktiviert.';
$string['blocked_ratelimit'] = 'Ausgehender Aufruf blockiert: Die Ratenbegrenzung wurde erreicht.';
$string['brain_activate'] = 'Aktivieren';
$string['brain_activate_failed'] = 'Dieses Brain kann nicht aktiviert werden: unbekannt für diesen API-Schlüssel oder nicht mehr aktiv.';
$string['brain_activated'] = 'Diese Website verwendet jetzt das Brain "{$a}".';
$string['brain_active'] = 'Aktiv';
$string['brain_col_action'] = 'Aktion';
$string['brain_col_created'] = 'Erstellt';
$string['brain_col_id'] = 'Brain-ID';
$string['brain_col_language'] = 'Sprache';
$string['brain_col_status'] = 'Status';
$string['brain_col_tag'] = 'Tag';
$string['brain_deleted'] = 'Gelöscht';
$string['brain_gotosettings'] = 'Zurück zu den Tarsus-Einstellungen';
$string['brain_inactive'] = 'Inaktiv';
$string['brain_inuse'] = 'In Verwendung';
$string['brain_missing'] = 'Diese Website ist auf das Brain "{$a}" eingestellt, der API-Schlüssel kennt es aber nicht mehr. Es wurde vermutlich gelöscht. Bitte unten ein vorhandenes Brain aktivieren.';
$string['brain_none_found'] = 'Der API-Schlüssel hat keine Brains geliefert. Basis-URL und API-Schlüssel prüfen oder Tarsus um Freischaltung bitten.';
$string['brain_none_selected'] = 'Es ist noch kein Brain ausgewählt. Indexierung und Chat bleiben blockiert, bis eines aktiviert wird.';
$string['brainid'] = 'Brain-ID';
$string['brainid_desc'] = 'Das einzige von dieser Website genutzte Tarsus-Brain.';
$string['brains'] = 'Tarsus-Brains';
$string['brains_desc'] = 'Alle Brains, die mit dem obigen API-Schlüssel erreichbar sind, samt Status. <a href="{$a}">Brain-Liste öffnen</a>, um das Brain dieser Website auszuwählen.';
$string['creditbudget'] = 'Credit-Budget (pro Zeitraum)';
$string['creditbudget_desc'] = 'Maximale aiCredits pro Zeitraum, bevor Aufrufe abgelehnt werden.';
$string['deletions'] = 'Tarsus-Löschungen';
$string['deletions_none'] = 'Alles, was auf dieser Website gelöscht wurde, ist auch im Brain gelöscht.';
$string['deletions_pending'] = 'Das Brain hat {$a} Löschung(en) nicht bestätigt. Das passiert, wenn es im Moment des Löschens nicht erreichbar ist: Der Inhalt ist aus Moodle verschwunden, kann aber weiterhin Fragen beantworten.';
$string['deletions_resource'] = 'Ressource';
$string['deletions_retried'] = '{$a} Löschung(en) abgeschlossen.';
$string['deletions_retry'] = 'Jetzt erneut versuchen';
$string['deletions_since'] = 'Wartet auf Entfernung seit';
$string['killswitch'] = 'Not-Aus';
$string['killswitch_desc'] = 'Wenn aktiviert, werden alle ausgehenden Aufrufe sofort blockiert. Statusabfragen funktionieren weiter; die Warteschlange bleibt erhalten.';
$string['lockfailed'] = 'Die Sperre für den Schutzmechanismus-Status konnte nicht erlangt werden.';
$string['mockmode'] = 'Mock-Modus';
$string['mockmode_desc'] = 'Wenn aktiviert, wird der gesamte Tarsus-Verkehr von einem deterministischen Mock bedient — keine Live-API-Aufrufe.';
$string['nobrainselected'] = 'Es ist kein Tarsus-Brain ausgewählt. Bitte eines auf der Seite "Tarsus-Brains" auswählen.';
$string['notconfigured'] = 'Tarsus ist nicht konfiguriert: Basis-URL, API-Schlüssel und Brain-ID hinterlegen oder den Mock-Modus aktivieren.';
$string['pluginname'] = 'Tarsus';
$string['privacy:metadata:local_tarsus_usage'] = 'Lokales Protokoll der Tarsus-Vorgänge zur Nutzungszuordnung.';
$string['privacy:metadata:local_tarsus_usage:contextid'] = 'Der Kontext, in dem der Vorgang stattfand.';
$string['privacy:metadata:local_tarsus_usage:credits'] = 'Die vom Vorgang verbrauchten aiCredits.';
$string['privacy:metadata:local_tarsus_usage:operation'] = 'Der ausgeführte Vorgang (z. B. fundierte Abfrage).';
$string['privacy:metadata:local_tarsus_usage:timecreated'] = 'Zeitpunkt des Vorgangs.';
$string['privacy:metadata:local_tarsus_usage:userid'] = 'Die Person, die den Vorgang ausgelöst hat.';
$string['privacy:metadata:tarsus'] = 'An die Tarsus-RAG-Engine übermittelte Daten, um Antworten in Kursmaterialien zu verankern.';
$string['privacy:metadata:tarsus:language'] = 'Die Oberflächensprache der Person, damit der Assistent in dieser Sprache antwortet.';
$string['privacy:metadata:tarsus:query'] = 'Der für die fundierte Abfrage gesendete Abfragetext.';
$string['privacy:metadata:tarsus:userid'] = 'Ein stabiles Pseudonym (HMAC der Moodle-Benutzer-ID); E-Mail-Adressen werden nie gesendet. Da das Pseudonym umkehrbar ist, bleibt es personenbezogen.';
$string['privacy:metadata:tarsus:username'] = 'Der Anzeigename der Person, damit der Assistent sie im Chat persönlich ansprechen kann.';
$string['ratelimit'] = 'Ratenbegrenzung (Aufrufe pro Minute)';
$string['ratelimit_desc'] = 'Maximale Anzahl ausgehender Aufrufe pro Minute (Token-Bucket).';
$string['robotavatar'] = 'Avatar des Assistenten';
$string['robotavatar_desc'] = 'Das Bild neben den Antworten des Assistenten im Chat. Quadratische Bilder eignen sich am besten.';
$string['robotdescription'] = 'Beschreibung des Assistenten';
$string['robotdescription_desc'] = 'Eine kurze Beschreibung des Assistenten, z. B. Rolle oder Anweisungen.';
$string['robotid'] = 'Assistenten-ID';
$string['robotid_desc'] = 'Die Datenbank-ID dieses Assistenten auf Tarsus-Seite, wie von Tarsus mitgeteilt.';
$string['robotname'] = 'Name des Assistenten';
$string['robotname_desc'] = 'Der globale Name des KI-Assistenten, wie Lernende ihn sehen.';
$string['settings_assistant'] = 'Assistent';
$string['settings_assistant_desc'] = 'Wie sich der KI-Assistent im Aktivitäts-Chat vorstellt. Eine Identität für die gesamte Organisation.';
$string['settings_connection'] = 'Tarsus-Verbindung';
$string['settings_connection_desc'] = 'Diese Website mit Tarsus verbinden. Ein Brain pro Kunde — einmalig eingetragen, keine Verwaltung des Lebenszyklus.';
$string['settings_guardrails'] = 'Schutzmechanismen';
$string['settings_guardrails_desc'] = 'Begrenzen Verfügbarkeit und Credit-Verbrauch. Sie schützen den Endpunkt und das Budget.';
$string['status:error'] = 'Fehler';
$string['status:indexed'] = 'Indexiert';
$string['status:notindexed'] = 'Nicht indexiert';
$string['status:pending'] = 'Ausstehend';
$string['status:pendingconfirmation'] = 'Warten auf Bestätigung';
$string['status:pendingdeletion'] = 'Entfernung ausstehend';
$string['status:pendingreindex'] = 'Inhalt geändert';
$string['transporterror'] = 'Tarsus-Übertragungsfehler (HTTP {$a}).';
$string['voice'] = 'Stimme';
$string['voice_desc'] = 'Eine Stimme aus der Liste des Brains. Im festen Modus für alle, im automatischen Modus als Rückfall.';
$string['voice_female'] = 'weiblich';
$string['voice_label'] = '{$a->alias} ({$a->language}, {$a->gender})';
$string['voice_male'] = 'männlich';
$string['voice_mode'] = 'Stimmauswahl';
$string['voice_mode_auto'] = 'Nach der Sprache der Lernenden (die Stimme unten, wenn es für die Sprache keine gibt)';
$string['voice_mode_desc'] = 'Wie die Stimme gewählt wird, die Lerneinheiten vorliest.';
$string['voice_mode_fixed'] = 'Eine Stimme für alle';
