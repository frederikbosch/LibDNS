<?php

  namespace DaveRandom\LibDNS\DataTypes\Vectors;

  use \DaveRandom\LibDNS\Packet;
  use \DaveRandom\LibDNS\PacketBuilder\PacketBuilder;
  use \DaveRandom\LibDNS\DataTypes\Vector;
  use \DaveRandom\LibDNS\DataTypes\DomainName;
  use \DaveRandom\LibDNS\DataTypes\Short;

  class MX extends Vector {

    private $preferenceData;
    private $exchangeData;

    public function loadFromPacket(Packet $packet, $dataLength = NULL) {
      $preference = (new Short)->loadFromPacket($packet);
      $exchange = (new DomainName)->loadFromPacket($packet);
      $this->__construct($preference, $exchange);
      return $this;
    }

    public function writeToPacket(PacketBuilder $packetBuilder, $withLengthWord = FALSE) {
      $packetBuilder
        ->addWriteBlock(TRUE)
        ->write($this->preferenceData->getRawData())
        ->writeDomainName($this->exchangeData);
    }

    public function __construct($preference = NULL, $exchange = NULL) {
      $this->preferenceData = $preference instanceof Short ? $preference : new Short($preference);
      $this->exchangeData = $exchange instanceof DomainName ? $exchange : new DomainName($exchange);
    }

    protected function constructRawData() {
      return $this->preferenceData->getRawData().$this->exchangeData->getRawData();
    }

    protected function constructFormattedData() {
      return $this->preferenceData->getFormattedData().' '.$this->exchangeData->getFormattedData();
    }

    public function preference($newValue = NULL) {
      if ($newValue === NULL) {
        $result = $this->preferenceData;
      } else {
        $this->preferenceData = $newValue instanceof Short ? $newValue : new Short($newValue);
        $result = $this;
      }
      return $result;
    }

    public function exchange($newValue = NULL) {
      if ($newValue === NULL) {
        $result = $this->exchangeData;
      } else {
        $this->exchangeData = $newValue instanceof DomainName ? $newValue : new DomainName($newValue);
        $result = $this;
      }
      return $result;
    }

  }
