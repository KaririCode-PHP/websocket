<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Exception;

use KaririCode\Exception\CodeBlock;
use KaririCode\Exception\ErrorCode;

/**
 * The error codes of kariricode/websocket: block 1800–1899 of the
 * ecosystem registry under the prefix WS, so a log line that starts with
 * `[WS_1811]` names this package and one failure. A frame failure also
 * carries the RFC 6455 close code the server sends (1002, 1007, 1009); the
 * WS code says which rule broke. The layout:
 *
 *     1800–1819  frames and close payloads from the client
 *     1820–1839  the opening handshake
 *     1840–1849  messages and close frames the application sends
 *     1850–1859  connections and the server socket
 *     1860–1869  configuration
 *     1870–1879  the router normalizer
 *
 * Preconditions: a code is stable API (registry rule 4). A case is never
 * renumbered and a retired value is never reused.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $parser->parse($buffer);
 *     } catch (FrameException $e) {
 *         $e->errorCode;  // WsCode::UnmaskedClientFrame (1802)
 *         $e->closeCode;  // CloseCode::ProtocolError (1002), sent to the client
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.4; RFC 6455 §7.4.1; kariricode/exception
 * ErrorCode and CODE-BLOCKS.md; ADR-003.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/CODE-BLOCKS.md
 */
enum WsCode: int implements ErrorCode
{
    case ReservedOpcode = 1800;
    case ReservedBits = 1801;
    case UnmaskedClientFrame = 1802;
    case ControlFrameTooLarge = 1803;
    case FragmentedControlFrame = 1804;
    case FrameTooLarge = 1805;
    case NonMinimalLength = 1806;
    case LengthOutOfRange = 1807;
    case MessageTooLarge = 1808;
    case UnexpectedContinuation = 1809;
    case InterleavedDataFrame = 1810;
    case InvalidUtf8 = 1811;
    case InvalidClosePayload = 1812;
    case InvalidCloseCode = 1813;
    case InvalidCloseReason = 1814;
    case HandshakeTooLarge = 1820;
    case MalformedRequestLine = 1821;
    case MethodNotGet = 1822;
    case UnsupportedHttpVersion = 1823;
    case MalformedHeader = 1824;
    case MissingHost = 1825;
    case MissingUpgrade = 1826;
    case MissingConnectionUpgrade = 1827;
    case UnsupportedVersion = 1828;
    case InvalidKey = 1829;
    case OriginRejected = 1830;
    case DuplicateHeader = 1831;
    case TextNotUtf8 = 1840;
    case CloseReasonTooLong = 1841;
    case CloseReasonNotUtf8 = 1842;
    case UnsendableCloseCode = 1843;
    case ControlPayloadTooLarge = 1844;
    case ControlOpcodeInMessage = 1845;
    case NotOpen = 1850;
    case BindFailed = 1851;
    case NonPositiveLimit = 1860;
    case NegativeTimeout = 1861;
    case InvalidOrigin = 1862;
    case InvalidSubprotocol = 1863;
    case NotAConnection = 1870;

    #[\Override]
    public static function prefix(): string
    {
        return 'WS';
    }

    #[\Override]
    public static function block(): CodeBlock
    {
        return new CodeBlock(1800, 1899);
    }
}
