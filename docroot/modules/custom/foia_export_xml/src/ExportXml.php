<?php

namespace Drupal\foia_export_xml;

use Drupal\node\Entity\Node;

/**
 * Class ExportXml.
 *
 * Generate an XML string from a node of type annual_foia_report_data.
 */
class ExportXml {

  /**
   * The DOMDocument object.
   *
   * @var \DOMDocument
   */
  protected $document;

  /**
   * The root element of the DOMDocument object.
   *
   * @var \DOMElement
   */
  protected $root;

  /**
   * The node being processed.
   *
   * @var Drupal\node\Entity\Node
   */
  protected $node;

  /**
   * A map of component IDs to local identifiers.
   *
   * Keys are node IDs for agency_component nodes. Values are identifiers used
   * in the XML: "ORG1", "ORG2", etc.
   *
   * @var string
   */
  protected $componentMap = [];

  /**
   * Cast an ExportXml object to string.
   *
   * @return string
   *   An XML representation of the annual report.
   */
  public function __toString() {
    return $this->document->saveXML();
  }

  /**
   * Construct an ExportXml object with root element and header information.
   *
   * @param Drupal\node\Entity\Node $node
   *   A node of type annual_foia_report_data.
   */
  public function __construct(Node $node) {
    $this->node = $node;
    $date = $this->node->field_date_prepared->value;
    $snippet = <<<EOS
<?xml version="1.0"?>
<iepd:FoiaAnnualReport xmlns:iepd="http://leisp.usdoj.gov/niem/FoiaAnnualReport/exchange/1.03" xsi:schemaLocation="http://leisp.usdoj.gov/niem/FoiaAnnualReport/exchange/1.03 ../schema/exchange/FoiaAnnualReport.xsd" xmlns:foia="http://leisp.usdoj.gov/niem/FoiaAnnualReport/extension/1.03" xmlns:i="http://niem.gov/niem/appinfo/2.0" xmlns:j="http://niem.gov/niem/domains/jxdm/4.1" xmlns:nc="http://niem.gov/niem/niem-core/2.0" xmlns:s="http://niem.gov/niem/structures/2.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <nc:DocumentApplicationName nc:applicationVersionText="1.1">FOIA Annual Report Workbook</nc:DocumentApplicationName>
  <nc:DocumentCreationDate>
    <nc:Date>$date</nc:Date>
  </nc:DocumentCreationDate>
  <nc:DocumentDescriptionText>FOIA Annual Report</nc:DocumentDescriptionText>
</iepd:FoiaAnnualReport>
EOS;
    $this->document = new \DOMDocument('1.0');
    $this->document->loadXML($snippet);
    $this->root = $this->document->getElementsByTagNameNS('http://leisp.usdoj.gov/niem/FoiaAnnualReport/exchange/1.03', 'FoiaAnnualReport')[0];

    $this->organization();
    $this->processedRequestSection();
  }

  /**
   * Add an element to the DOMDocument object.
   *
   * @param string $tag
   *   The tag name, in the format "prefix:localName".
   * @param \DOMElement $parent
   *   The parent of the new element.
   * @param string $value
   *   (optional) The text value of the new element.
   *
   * @return \DOMElement
   *   The newly added element.
   */
  protected function addElementNs($tag, \DOMElement $parent, $value = NULL) {
    $namespaces = [
      'iepd' => 'http://leisp.usdoj.gov/niem/FoiaAnnualReport/exchange/1.03',
      'foia' => 'http://leisp.usdoj.gov/niem/FoiaAnnualReport/extension/1.03',
      'i' => 'http://niem.gov/niem/appinfo/2.0',
      'j' => 'http://niem.gov/niem/domains/jxdm/4.1',
      'nc' => 'http://niem.gov/niem/niem-core/2.0',
      's' => 'http://niem.gov/niem/structures/2.0',
      'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
    ];
    list($prefix, $local_name) = explode(':', $tag, 2);
    if (empty($namespaces[$prefix])) {
      throw new \Exception("Unrecognized prefix: $prefix");
    }
    $element = $this->document->createElementNS($namespaces[$prefix], $local_name, $value);
    $parent->appendChild($element);
    return $element;
  }

  /**
   * Agency Information.
   *
   * This corresponds to the Agency Information section of the annual report.
   */
  protected function organization() {
    $agency = $this->node->field_agency->referencedEntities()[0];

    // Add abbreviation and name for the agency.
    $org = $this->addElementNs('nc:Organization', $this->root);
    $org->setAttribute('s:id', 'ORG0');
    $item = $this->addElementNs('nc:OrganizationAbbreviationText', $org, $agency->field_agency_abbreviation->value);
    $item = $this->addElementNs('nc:OrganizationName', $org, $agency->label());

    // Add abbreviation and name for each component and populate
    // $this->componentMap.
    foreach ($this->node->field_agency_components->referencedEntities() as $delta => $component) {
      $local_id = 'ORG' . ($delta + 1);
      $this->componentMap[$component->id()] = $local_id;

      $suborg = $this->addElementNs('nc:OrganizationSubUnit', $org);
      $suborg->setAttribute('s:id', $local_id);
      $item = $this->addElementNs('nc:OrganizationAbbreviationText', $suborg, $component->field_agency_comp_abbreviation->value);
      $item = $this->addElementNs('nc:OrganizationName', $suborg, $component->label());
    }

    // Add the fiscal year.
    $this->addElementNs('foia:DocumentFiscalYearDate', $this->root, $this->node->field_foia_annual_report_yr->value);
  }

  /**
   * Received, Processed and Pending FOIA Requests.
   *
   * This corresponds to Section V.A of the annual report.
   */
  protected function processedRequestSection() {
    $component_data = $this->node->field_foia_requests_va->referencedEntities();
    $map = [
      'field_req_pend_start_yr' => 'foia:ProcessingStatisticsPendingAtStartQuantity',
      'field_req_received_yr' => 'foia:ProcessingStatisticsReceivedQuantity',
      'field_req_processed_yr' => 'foia:ProcessingStatisticsProcessedQuantity',
      'field_req_pend_end_yr' => 'foia:ProcessingStatisticsPendingAtEndQuantity',
    ];
    $overall_map = [
      'field_overall_req_pend_start_yr' => 'foia:ProcessingStatisticsPendingAtStartQuantity',
      'field_overall_req_received_yr' => 'foia:ProcessingStatisticsReceivedQuantity',
      'field_overall_req_processed_yr' => 'foia:ProcessingStatisticsProcessedQuantity',
      'field_overall_req_pend_end_yr' => 'foia:ProcessingStatisticsPendingAtEndQuantity',
    ];
    $section = $this->addElementNs('foia:ProcessedRequestSection', $this->root);

    // Add data for each component.
    foreach ($component_data as $delta => $component) {
      $item = $this->addElementNs('foia:ProcessingStatistics', $section);
      $item->setAttribute('s:id', 'PS' . ($delta + 1));
      foreach ($map as $field => $tag) {
        $this->addElementNs($tag, $item, $component->get($field)->value);
      }
    }

    // Add overall data.
    $item = $this->addElementNs('foia:ProcessingStatistics', $section);
    $item->setAttribute('s:id', 'PS0');
    foreach ($overall_map as $field => $tag) {
      $this->addElementNs($tag, $item, $this->node->get($field)->value);
    }

    // Add processing association for each component.
    foreach ($component_data as $delta => $component) {
      $matchup = $this->addElementNs('foia:ProcessingStatisticsOrganizationAssociation', $section);
      $local_name = $this->addElementNs('foia:ComponentDataReference', $matchup);
      $local_name->setAttribute('s:ref', 'PS' . ($delta + 1));
      $org_name = $this->addElementNs('nc:OrganizationReference', $matchup);
      $agency_component = $component->field_agency_component->referencedEntities()[0];
      $org_name->setAttribute('s:ref', $this->componentMap[$agency_component->id()]);
    }

    // Add processing association for the agency overall.
    $matchup = $this->addElementNs('foia:ProcessingStatisticsOrganizationAssociation', $section);
    $local_name = $this->addElementNs('foia:ComponentDataReference', $matchup);
    $local_name->setAttribute('s:ref', 'PS' . 0);
    $org_name = $this->addElementNs('nc:OrganizationReference', $matchup);
    $org_name->setAttribute('s:ref', 'ORG' . 0);

    // Add footnote.
    $footnote = trim(strip_tags($this->node->field_footnotes_va->value));
    $this->addElementNs('foia:FootnoteText', $section, $footnote);
  }

}
