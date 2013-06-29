<?php
/**
 * Decodes raw network data to Message objects
 *
 * PHP version 5.4
 *
 * @category   LibDNS
 * @package    Decoder
 * @author     Chris Wright <https://github.com/DaveRandom>
 * @copyright  Copyright (c) Chris Wright <https://github.com/DaveRandom>
 * @license    http://www.opensource.org/licenses/mit-license.html  MIT License
 * @version    2.0.0
 */
namespace LibDNS\Decoder;

use \LibDNS\Packets\PacketFactory,
    \LibDNS\Packets\Packet,
    \LibDNS\Messages\MessageFactory,
    \LibDNS\Messages\Message,
    \LibDNS\Records\QuestionFactory,
    \LibDNS\Records\ResourceBuilder,
    \LibDNS\DataTypes\DataTypeFactory,
    \LibDNS\DataTypes\SimpleType,
    \LibDNS\DataTypes\ComplexType,
    \LibDNS\DataTypes\SimpleTypes,
    \LibDNS\DataTypes\Anything,
    \LibDNS\DataTypes\BitMap,
    \LibDNS\DataTypes\Char,
    \LibDNS\DataTypes\CharacterString,
    \LibDNS\DataTypes\DomainName,
    \LibDNS\DataTypes\IPv4Address,
    \LibDNS\DataTypes\IPv6Address,
    \LibDNS\DataTypes\Long,
    \LibDNS\DataTypes\Short;

/**
 * Decodes raw network data to Message objects
 *
 * @category   LibDNS
 * @package    Decoder
 * @author     Chris Wright <https://github.com/DaveRandom>
 */
class Decoder
{
    const LABELTYPE_LABEL   = 0b00000000;
    const LABELTYPE_POINTER = 0b11000000;

    /**
     * @var \LibDNS\Packets\PacketFactory
     */
    private $packetFactory;

    /**
     * @var \LibDNS\Messages\MessageFactory
     */
    private $messageFactory;

    /**
     * @var \LibDNS\Records\QuestionFactory
     */
    private $questionFactory;

    /**
     * @var \LibDNS\Records\ResourceBuilder
     */
    private $resourceBuilder;

    /**
     * @var \LibDNS\Decoder\DecodingContextFactory
     */
    private $decodingContextFactory;

    /**
     * Constructor
     *
     * @param \LibDNS\Packets\PacketFactory $packetFactory
     * @param \LibDNS\Messages\MessageFactory $messageFactory
     * @param \LibDNS\Records\QuestionFactory $questionFactory
     * @param \LibDNS\Records\ResourceBuilder $resourceBuilder
     * @param \LibDNS\DataTypes\DataTypeFactory $dataTypeFactory
     * @param \LibDNS\Decoder\DecodingContextFactory $decodingContextFactory
     */
    public function __construct(
        PacketFactory $packetFactory,
        MessageFactory $messageFactory,
        QuestionFactory $questionFactory,
        ResourceBuilder $resourceBuilder,
        DataTypeFactory $dataTypeFactory,
        DecodingContextFactory $decodingContextFactory
    ) {
        $this->packetFactory = $packetFactory;
        $this->messageFactory = $messageFactory;
        $this->questionFactory = $questionFactory;
        $this->resourceBuilder = $resourceBuilder;
        $this->dataTypeFactory = $dataTypeFactory;
        $this->decodingContextFactory = $decodingContextFactory;
    }

    /**
     * Read a specified number of bytes of data from a packet
     *
     * @param \LibDNS\Packets\Packet $packet
     * @param int                    $length
     *
     * @return string
     *
     * @throws \UnexpectedValueException When the read operation does not result in the requested number of bytes
     */
    private function readDataFromPacket(Packet $packet, $length)
    {
        if ($packet->getBytesRemaining() < $length) {
            throw new \UnexpectedValueException('Decode error: Incomplete packet');
        }

        return $packet->read($length);
    }

    /**
     * Decode the header section of the message
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\Messages\Message      $message
     *
     * @throws \UnexpectedValueException When the header section is invalid
     */
    private function decodeHeader(DecodingContext $decodingContext, Message $message)
    {
        $header = unpack('nid/c2meta/nqd/nan/nns/nar', $this->readDataFromPacket($decodingContext->getPacket(), 96));
        if (!$header) {
            throw new \UnexpectedValueException('Decode error: Header unpack failed');
        }

        $message->setID($header['id']);
        $message->setType(($header['meta1'] & 0b10000000) >> 8);
        $message->setOpCode(($header['meta1'] & 0b01111000) >> 3);
        $message->isAuthoritative(($header['meta1'] & 0b00000100) >> 2);
        $message->isTruncated(($header['meta1'] & 0b00000010) >> 1);
        $message->isRecusionDesired($header['meta1'] & 0b00000001);
        $message->isRecusionAvailable(($header['meta2'] & 0b10000000) >> 8);
        $message->setResponseCode($header['meta2'] & 0b00001111);

        $decodingContext->setExpectedQuestionRecords($header['qd']);
        $decodingContext->setExpectedAnswerRecords($header['an']);
        $decodingContext->setExpectedAuthorityRecords($header['qd']);
        $decodingContext->setExpectedAdditoinalRecords($header['ar']);
    }

    /**
     * Decode an Anything field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param int                           $length
     * @param \LibDNS\DataTypes\Anything    $anything       The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeAnything(DecodingContext $decodingContext, Anything $anything, $length)
    {
        $anything->setValue($this->readDataFromPacket($decodingContext->getPacket(), $length));

        return $length;
    }

    /**
     * Decode a BitMap field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param int                           $length
     * @param \LibDNS\DataTypes\BitMap      $bitMap         The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeBitMap(DecodingContext $decodingContext, BitMap $bitMap, $length)
    {
        $bitMap->setValue($this->readDataFromPacket($decodingContext->getPacket(), $length));

        return $length;
    }

    /**
     * Decode a Char field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\Char        $char           The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeChar(DecodingContext $decodingContext, Char $char)
    {
        $value = unpack('C', $this->readDataFromPacket($decodingContext->getPacket(), 1))[1];
        $char->setValue($value);

        return 1;
    }

    /**
     * Decode a CharacterString field
     *
     * @param \LibDNS\Decoder\DecodingContext     $decodingContext
     * @param \LibDNS\DataTypes\CharacterString $characterString The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeCharacterString(DecodingContext $decodingContext, CharacterString $characterString)
    {
        $packet = $decodingContext->getPacket();
        $length = ord($this->readDataFromPacket($packet, 1));
        $characterString->setValue($this->readDataFromPacket($packet, $length));

        return $length + 1;
    }

    /**
     * Decode a DomainName field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\DomainName  $domainName     The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeDomainName(DecodingContext $decodingContext, DomainName $domainName)
    {
        $packet = $decodingContext->getPacket();
        $labelRegistry = $decodingContext->getLabelRegistry();

        $labels = [];
        $totalLength = 0;

        while ($length = ord($this->readDataFromPacket($packet, 1))) {
            $totalLength++;

            if ($length === 0) {
                break;
            }

            $labelType = $length & 0b11000000;
            if ($labelType === self::LABELTYPE_LABEL) {
                $index = $packet->getPointer() - 1;
                $label = $this->readDataFromPacket($packet, $length);

                array_unshift($labels, [$index, $label]);
                $totalLength += $length;
            } else if ($labelType === self::LABELTYPE_POINTER) {
                $index = (($length & 0b00111111) << 8) | ord($this->readDataFromPacket($packet, 1));
                $ref = $labelRegistry->lookupLabel($index);
                if ($ref === null) {
                    throw new \UnexpectedValueException('Decode error: Invalid compression pointer reference in domain name');
                }

                array_unshift($labels, $ref);
                $totalLength++;

                break;
            } else {
                throw new \UnexpectedValueException('Decode error: Invalid label type in domain name');
            }
        }

        $result = [];
        foreach ($labels as $label) {
            if (is_int($label[0])) {
                array_unshift($result, $label[1]);
                $labelRegistry->register($result, $label[0]);
            } else {
                $result = $label;
            }
        }
        $domainName->setValue($result);

        return $totalLength;
    }

    /**
     * Decode an IPv4Address field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\IPv4Address $ipv4Address    The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeIPv4Address(DecodingContext $decodingContext, IPv4Address $ipv4Address)
    {
        $octets = unpack('C4', $this->readDataFromPacket($decodingContext->getPacket(), 4));
        $ipv4Address->setOctets($octets);

        return 4;
    }

    /**
     * Decode an IPv6Address field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\IPv6Address $ipv6Address    The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeIPv6Address(DecodingContext $decodingContext, IPv6Address $ipv6Address)
    {
        $shorts = unpack('n8', $this->readDataFromPacket($decodingContext->getPacket(), 16));
        $ipv6Address->setShorts($shorts);

        return 16;
    }

    /**
     * Decode a Long field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\Long        $long           The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeLong(DecodingContext $decodingContext, Long $long)
    {
        $value = unpack('N', $this->readDataFromPacket($decodingContext->getPacket(), 4))[1];
        $long->setValue($value);
    }

    /**
     * Decode a Short field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\Short       $short          The object to populate with the result
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeShort(DecodingContext $decodingContext, Short $short)
    {
        $value = unpack('n', $this->readDataFromPacket($decodingContext->getPacket(), 2))[1];
        $short->setValue($value);
    }

    /**
     * Decode a SimpleType field
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     * @param \LibDNS\DataTypes\SimpleType  $simpleType     The object to populate with the result
     * @param int                           $length         Expected data length
     *
     * @return int The number of packet bytes consumed by the operation
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     * @throws \InvalidArgumentException When the SimpleType subtype is unknown
     */
    private function decodeSimpleType(DecodingContext $decodingContext, SimpleType $simpleType, $length)
    {
        if ($simpleType instanceof Anything) {
            $result = $this->decodeAnything($decodingContext, $simpleType, $length);
        } else if ($simpleType instanceof BitMap) {
            $result = $this->decodeBitMap($decodingContext, $simpleType, $length);
        } else if ($simpleType instanceof Char) {
            $result = $this->decodeChar($decodingContext, $simpleType);
        } else if ($simpleType instanceof CharacterString) {
            $result = $this->decodeCharacterString($decodingContext, $simpleType);
        } else if ($simpleType instanceof DomainName) {
            $result = $this->decodeDomainName($decodingContext, $simpleType);
        } else if ($simpleType instanceof IPv4Address) {
            $result = $this->decodeIPv4Address($decodingContext, $simpleType);
        } else if ($simpleType instanceof IPv6Address) {
            $result = $this->decodeIPv6Address($decodingContext, $simpleType);
        } else if ($simpleType instanceof Long) {
            $result = $this->decodeLong($decodingContext, $simpleType);
        } else if ($simpleType instanceof Short) {
            $result = $this->decodeShort($decodingContext, $simpleType);
        } else {
            throw new \InvalidArgumentException('Unknown SimpleType ' . get_class($simpleType));
        }

        return $result;
    }

    /**
     * Decode a question record
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     *
     * @return \LibDNS\Records\Question
     *
     * @throws \UnexpectedValueException When the record is invalid
     */
    private function decodeQuestionRecord(DecodingContext $decodingContext)
    {
        $domainName = $this->dataTypeFactory->createDomainName();
        $this->decodeDomainName($decodingContext, $domainName);
        $meta = unpack('ntype/nclass', $this->readDataFromPacket($decodingContext->getPacket(), 4));

        $question = $this->questionFactory->create($meta['type']);
        $question->setName($domainName);
        $question->setClass($meta['class']);

        return $question;
    }

    /**
     * Decode a resource record
     *
     * @param \LibDNS\Decoder\DecodingContext $decodingContext
     *
     * @return \LibDNS\Records\Resource
     *
     * @throws \UnexpectedValueException When the record is invalid
     * @throws \InvalidArgumentException When a SimpleType subtype is unknown
     */
    private function decodeResourceRecord(DecodingContext $decodingContext)
    {
        $domainName = $this->dataTypeFactory->createDomainName();
        $this->decodeDomainName($decodingContext, $domainName);
        $meta = unpack('ntype/nclass/Nttl/nlength', $this->readDataFromPacket($decodingContext->getPacket(), 10));

        $resource = $this->resourceBuilder->build($meta['type']);
        $resource->setName($domainName);
        $resource->setClass($meta['class']);
        $resource->setTTL($meta['ttl']);

        $data = $resource->getData();
        if ($data instanceof SimpleType) {
            $this->decodeSimpleType($decodingContext, $data, $meta['length']);
        } else if ($data instanceof ComplexType) {
            foreach ($data as $simpleType) {
                $meta['length'] -= $this->decodeSimpleType($decodingContext, $simpleType, $meta['length']);
            }

            if ($meta['length'] !== 0) {
                throw new \UnexpectedValueException('Decode error: Invalid length for record data section');
            }
        } else {
            throw new \InvalidArgumentException('Unknown data type ' . get_class($simpleType));
        }

        return $question;
    }

    /**
     * Decode a Message from raw network data
     *
     * @param string $data The data string to decode
     *
     * @return \LibDNS\Messages\Message
     *
     * @throws \UnexpectedValueException When the packet data is invalid
     * @throws \InvalidArgumentException When a SimpleType subtype is unknown
     */
    public function decode($data)
    {
        $packet = $this->packetFactory->create($data);
        $decodingContext = $this->decodingContextFactory->create($packet);
        $message = $this->messageFactory->create();

        $this->decodeHeader($decodingContext, $message);

        $questionRecords = $message->getQuestionRecords();
        $expected = $decodingContext->getExpectedQuestionRecords();
        for ($i = 0; $i < $expected; $i++) {
            $questionRecords->add($this->decodeQuestionRecord($decodingContext));
        }

        $answerRecords = $message->getAnswerRecords();
        $expected = $decodingContext->getExpectedAnswerRecords();
        for ($i = 0; $i < $expected; $i++) {
            $answerRecords->add($this->decodeResourceRecord($decodingContext));
        }

        $authorityRecords = $message->getAuthorityRecords();
        $expected = $decodingContext->getExpectedAuthorityRecords();
        for ($i = 0; $i < $expected; $i++) {
            $authorityRecords->add($this->decodeResourceRecord($decodingContext));
        }

        $addtionalRecords = $message->getAddtionalRecords();
        $expected = $decodingContext->getExpectedAddtionalRecords();
        for ($i = 0; $i < $expected; $i++) {
            $addtionalRecords->add($this->decodeResourceRecord($decodingContext));
        }

        if ($packet->getBytesRemaining() !== 0) {
            throw new \UnexpectedValueException('Decode error: Unexpected data at end of packet');
        }

        return $message;
    }
}