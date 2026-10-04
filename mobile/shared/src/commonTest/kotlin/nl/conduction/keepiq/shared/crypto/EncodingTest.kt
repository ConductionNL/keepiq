// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlin.test.Test
import kotlin.test.assertEquals

class EncodingTest {
    @Test
    fun aLoneSurrogateEncodesAsTextEncoderDoes() {
        assertEquals("61efbfbd62", Encoding.hex(Encoding.utf8("a\uD800b")))
        assertEquals("f09f9490", Encoding.hex(Encoding.utf8("🔐")))
    }

    @Test
    fun aLeadingByteOrderMarkIsDroppedOnDecodeLikeTextDecoder() {
        assertEquals("bom", Encoding.fromUtf8(Encoding.utf8("﻿bom")))
        assertEquals("a﻿", Encoding.fromUtf8(Encoding.utf8("a﻿")))
    }

    @Test
    fun base64UrlHasNoPaddingAndReadsBothForms() {
        val bytes = byteArrayOf(-5, -1, 0)
        assertEquals("-_8A", Encoding.toBase64Url(bytes))
        assertEquals("+/8A", Encoding.toBase64(bytes))
        assertEquals(Encoding.hex(byteArrayOf(-5)), Encoding.hex(Encoding.fromBase64Url("-w")))
        assertEquals(Encoding.hex(byteArrayOf(-5)), Encoding.hex(Encoding.fromBase64Url("-w==")))
    }

    @Test
    fun sendLinkEncodesTheTokenLikeEncodeUriComponent() {
        assertEquals("a%20b%2F%C3%A9-_.!~*'()", SendCrypto.encodeUriComponent("a b/é-_.!~*'()"))
    }
}
