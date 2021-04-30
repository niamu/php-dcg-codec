<?php

require_once "common.php";

class DCGDeckEncoder {
  private static function ExtractNBitsWithCarry($value, $numBits) {
    $unLimitBit = 1 << $numBits;
    $unResult = ($value & ($unLimitBit - 1));
    if($value >= $unLimitBit) {
      $unResult |= $unLimitBit;
    }

    return $unResult;
  }

  private static function AddByte(&$bytes, $byte) {
    if($byte > 255)
      return false;

    array_push($bytes, $byte);
    return true;
  }

  private static function AddRemainingNumberToBuffer(
    $unValue,
    $unAlreadyWrittenBits,
    &$bytes
  ) {
    $unValue >>= $unAlreadyWrittenBits;
    $unNumBytes = 0;
    while ($unValue > 0) {
      $unNextByte = DCGDeckEncoder::ExtractNBitsWithCarry($unValue, 7);
      $unValue >>= 7;
      if(!DCGDeckEncoder::AddByte($bytes, $unNextByte))
        return false;

      $unNumBytes++;
    }
    return true;
  }

  private static function ComputeChecksum(&$bytes, $unNumBytes) {
    $unChecksum = 0;
    for ($i = HEADER_SIZE; $i < $unNumBytes + HEADER_SIZE; $i++) {
      $byte = $bytes[$i];
      $unChecksum += $byte;
    }
    return $unChecksum;
  }

  private static function EncodeBytes($deckContents) {
    if(
      !isset($deckContents) ||
      !isset($deckContents["digi-eggs"]) ||
      !isset($deckContents["deck"])
    )
      return false;

    $digiEggs = $deckContents["digi-eggs"];
    $deck = $deckContents["deck"];

    {
      $cardNumber  = array_column($digiEggs, "number");
      $cardParallelId = array_column($digiEggs, "parallel-id");
      array_multisort(
        $cardNumber, SORT_ASC,
        $cardParallelId, SORT_ASC,
        $digiEggs
      );

      $cardNumber  = array_column($deck, "number");
      $cardParallelId = array_column($deck, "parallel-id");
      array_multisort(
        $cardNumber, SORT_ASC,
        $cardParallelId, SORT_ASC,
        $deck
      );
    }

    $countDigiEggs = count($digiEggs);
    
    $groupedDigiEggs = array();
    foreach($digiEggs as $card) {
      $cardNumberSplit = preg_split("/-/", $card["number"]);
      $groupedDigiEggs[serialize(
        array(
          "cardSet" => $cardNumberSplit[0],
          "pad" => strlen($cardNumberSplit[1])
        )
      )][] = $card;
    }

    $groupedDeck = array();
    foreach($deck as $card) {
      $cardNumberSplit = preg_split("/-/", $card["number"]);
      $groupedDeck[serialize(
        array(
          "cardSet" => $cardNumberSplit[0],
          "pad" => strlen($cardNumberSplit[1])
        )
      )][] = $card;
    }

    $bytes = array();
    $version = (VERSION << 4) | DCGDeckEncoder::ExtractNBitsWithCarry(
      $countDigiEggs, 3
    );
    if(!DCGDeckEncoder::AddByte($bytes, $version))
      return false;

    $nChecksumBytePos = 1;
    if(!DCGDeckEncoder::AddByte($bytes, $nChecksumBytePos))
      return false;

    // TODO: This doesn't support Kanji
    // (character length may not match byte length)
    $nameLen = 0;
    if(isset($deckContents["name"])) {
      $name = $deckContents["name"];
      $trimLen = strlen($name);
      while($trimLen > 63)
      {
        $amountToTrim = floor(($trimLen - 63) / 4);
        $amountToTrim = ($amountToTrim > 1) ? $amountToTrim : 1;
        $name = mb_substr($name, 0, mb_strlen($name) - $amountToTrim);
        $trimLen = strlen($name);
      }

      $nameLen = strlen($name);
    }

    if(!DCGDeckEncoder::AddByte($bytes, $nameLen))
      return false;

    foreach (array($groupedDigiEggs, $groupedDeck) as $d) {
      foreach ($d as $cardSetAndPad => $cards) {
        $cardSet = strtoupper(unserialize($cardSetAndPad)["cardSet"]);
        $pad = unserialize($cardSetAndPad)["pad"];

        $cardSetLength = strlen($cardSet);
        for ($charIndex = 0; $charIndex < $cardSetLength; $charIndex++) {
          $byte = char_to_base36($cardSet[$charIndex]);
          if ($charIndex != $cardSetLength - 1)
            $byte = $byte | 0x80;
          if(!DCGDeckEncoder::AddByte($bytes, $byte))
            return false;
        }

        if(!DCGDeckEncoder::AddByte(
          $bytes, ((($pad - 1) << 6)) | count($cards)
        ))
          return false;

        $prevCardBase = 0;
        foreach ($cards as $card) {
          if($card["count"] == 0 || $card["count"] > 50)
            return false;

          $cardNumber = intval(preg_split("/-/", $card["number"])[1], 10);
          if($cardNumber <= 0)
            return false;

          if(!DCGDeckEncoder::AddByte($bytes, $card["count"] - 1))
            return false;

          $cardNumberOffset = ($cardNumber - $prevCardBase);
          $pIdAndOffset = (
            ($card["parallel-id"] << 5) | 
            DCGDeckEncoder::ExtractNBitsWithCarry($cardNumberOffset, 4)
          );
          if(!DCGDeckEncoder::AddByte($bytes, $pIdAndOffset))
            return false;
          if(!DCGDeckEncoder::AddRemainingNumberToBuffer(
            $cardNumberOffset, 4, $bytes
          ))
            return false;

          $prevCardBase = $cardNumber;
        }
      }
    }

    // Checksum
    $unFullChecksum = DCGDeckEncoder::ComputeChecksum(
      $bytes, count($bytes) - HEADER_SIZE
    );
    $unSmallChecksum = ($unFullChecksum & 0x0FF);
    $bytes[$nChecksumBytePos] = $unSmallChecksum;

    // Deck Name
    {
      $nameBytes = unpack("C*", $name);
      foreach($nameBytes as $nameByte)
      {
        if(!DCGDeckEncoder::AddByte($bytes, $nameByte))
          return false;
      }
    }

    return $bytes;
  }

  private static function EncodeBytesToString($bytes) {
    $byteCount = count($bytes);
    if ($byteCount == 0)
      return false;

    $packed = pack("C*", ...$bytes);
    $encoded = base64url_encode($packed);

    return PREFIX . $encoded;
  }

  public static function Encode($deckContents) {
    if(!$deckContents)
      return false;

    $bytes = DCGDeckEncoder::EncodeBytes($deckContents);
    if(!$bytes)
      return false;
    $deck_code = DCGDeckEncoder::EncodeBytesToString($bytes);
    return $deck_code;
  }
}
