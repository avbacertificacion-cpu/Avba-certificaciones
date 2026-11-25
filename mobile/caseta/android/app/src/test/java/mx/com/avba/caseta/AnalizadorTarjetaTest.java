package mx.com.avba.caseta;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertNull;

import org.junit.Test;

import java.util.ArrayList;
import java.util.List;

/** Casos de tarjetas: qué renglón es el nombre y cuál número es el código. */
public class AnalizadorTarjetaTest {

    private static List<AnalizadorTarjeta.Linea> tarjeta(Object... x) {
        List<AnalizadorTarjeta.Linea> l = new ArrayList<>();
        int y = 10;
        for (int i = 0; i < x.length; i += 2) {
            int alto = (Integer) x[i + 1];
            l.add(new AnalizadorTarjeta.Linea((String) x[i], y, 20, alto));
            y += alto + 12;
        }
        return l;
    }

    private static void ver(List<AnalizadorTarjeta.Linea> l, AnalizadorTarjeta.Reglas r, String codigo, String nombre) {
        AnalizadorTarjeta.Lectura le = AnalizadorTarjeta.analizar(l, r);
        assertEquals("código", codigo, le.codigo);
        assertEquals("nombre", nombre, le.nombre);
    }

    @Test public void gafeteConEtiqueta() {
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN PÉREZ LÓPEZ", 44, "TÉCNICO DE MANTENIMIENTO", 26, "No. Empleado: 004512", 26, "Vigencia: 31/12/2027", 22),
            new AnalizadorTarjeta.Reglas(), "004512", "Juan Pérez López");
    }

    @Test public void numeroSoloYTelefono() {
        ver(tarjeta("Quantum", 50, "Energy", 30, "MARÍA DE LA LUZ HERNÁNDEZ", 40, "12345678", 38, "TEL. EMERGENCIA 5512345678", 20),
            new AnalizadorTarjeta.Reglas(), "12345678", "María de la Luz Hernández");
    }

    @Test public void erroresDeLectura() {
        ver(tarjeta("QUANTUM ENERGY", 60, "ROBERTO SÁNCHEZ RUIZ", 40, "ID 1O25S", 30), new AnalizadorTarjeta.Reglas(), "10255", "Roberto Sánchez Ruiz");
    }

    @Test public void etiquetaNombreArriba() {
        ver(tarjeta("QUANTUM ENERGY MÉXICO", 50, "NOMBRE", 20, "CARLOS RUIZ ORTEGA", 30, "PUESTO", 20, "SUPERVISOR DE SEGURIDAD", 30, "FICHA", 20, "88231", 34),
            new AnalizadorTarjeta.Reglas(), "88231", "Carlos Ruiz Ortega");
    }

    @Test public void curpNssYFechasNoSonCodigo() {
        ver(tarjeta("QUANTUM", 60, "LUIS FLORES DÍAZ", 40, "CURP PEGJ800101HDFRRN09", 20, "No. 7788", 30), new AnalizadorTarjeta.Reglas(), "7788", "Luis Flores Díaz");
        ver(tarjeta("Quantum Energy", 50, "Ernesto Ramírez Vela", 40, "NSS 12345678901", 20, "CP 76000", 20, "Gafete 31415", 30),
            new AnalizadorTarjeta.Reglas(), "31415", "Ernesto Ramírez Vela");
        ver(tarjeta("QUANTUM ENERGY", 60, "PATRICIA GÓMEZ", 40, "VIGENCIA 2027", 20, "Expedición 12/05/2025", 20, "Empleado 56789", 30),
            new AnalizadorTarjeta.Reglas(), "56789", "Patricia Gómez");
    }

    @Test public void puestoNoEsNombre() {
        ver(tarjeta("QUANTUM ENERGY", 60, "INGENIERO DE PROYECTOS", 44, "No. 2210", 30), new AnalizadorTarjeta.Reglas(), "2210", null);
    }

    @Test public void reglasAjustables() {
        AnalizadorTarjeta.Reglas r6 = new AnalizadorTarjeta.Reglas();
        r6.longitud = 6;
        ver(tarjeta("QUANTUM ENERGY", 60, "SERGIO VEGA CRUZ", 40, "1234", 30, "Empleado 004512", 30, "Tel 4421234567", 20), r6, "004512", "Sergio Vega Cruz");
        AnalizadorTarjeta.Reglas rp = new AnalizadorTarjeta.Reglas();
        rp.ponerPatron("QE-?(\\d{5})");
        ver(tarjeta("QUANTUM ENERGY", 60, "RAÚL PEÑA SOTO", 40, "QE-20481", 30, "4421234567", 20), rp, "20481", "Raúl Peña Soto");
    }

    @Test public void nombreEnDosRenglones() {
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN CARLOS", 40, "PÉREZ LÓPEZ", 40, "No. 4471", 28), new AnalizadorTarjeta.Reglas(), "4471", "Juan Carlos Pérez López");
        ver(tarjeta("QUANTUM ENERGY", 60, "JUAN PÉREZ LÓPEZ", 40, "SUPERVISOR", 40, "Ficha 9921", 26), new AnalizadorTarjeta.Reglas(), "9921", "Juan Pérez López");
        ver(tarjeta("QUANTUM", 60, "MARÍA FERNANDA", 42, "RÍOS", 42, "Empleado 55120", 26), new AnalizadorTarjeta.Reglas(), "55120", "María Fernanda Ríos");
        ver(tarjeta("QUANTUM ENERGY", 60, "ANA LÓPEZ CRUZ", 40, "PLANTA SAN JUAN", 20, "No. 8080", 26), new AnalizadorTarjeta.Reglas(), "8080", "Ana López Cruz");
    }

    @Test public void letrerosDeIdentificacionNoSonNombre() {
        ver(tarjeta("JOSE MARCOS GONZALEZ", 36, "NACIONALIDAD", 22, "MEXICANA", 30, "No. 18438930", 28), new AnalizadorTarjeta.Reglas(), "18438930", "Jose Marcos Gonzalez");
        ver(tarjeta("MEXICANA NACIONALIDAD", 40, "Juan Pérez", 34, "ID 4471", 28), new AnalizadorTarjeta.Reglas(), "4471", "Juan Pérez");
        ver(tarjeta("FECHA DE NACIMIENTO", 30, "DOMICILIO", 20, "LAURA SOTO RIOS", 30, "Folio 99120", 26), new AnalizadorTarjeta.Reglas(), "99120", "Laura Soto Rios");
    }

    @Test public void estabilizadorPideVariasLecturasIguales() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(3, 6);
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12345", "Juan Pérez", 50)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura(null, null, 0)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12845", "Juan Pérez", 50)));
        assertNull(e.agregar(new AnalizadorTarjeta.Lectura("12345", null, 50)));
        AnalizadorTarjeta.Lectura ok = e.agregar(new AnalizadorTarjeta.Lectura("12345", "Juan Perez", 50));
        assertNotNull(ok);
        assertEquals("12345", ok.codigo);
    }

    @Test public void nombreLeidoSinElCodigoTambienCuenta() {
        AnalizadorTarjeta.Estabilizador e = new AnalizadorTarjeta.Estabilizador(2, 6);
        e.agregar(new AnalizadorTarjeta.Lectura(null, "Juan Pérez López", 0));
        e.agregar(new AnalizadorTarjeta.Lectura("4471", null, 50));
        AnalizadorTarjeta.Lectura ok = e.agregar(new AnalizadorTarjeta.Lectura("4471", null, 50));
        assertNotNull(ok);
        assertNull(ok.nombre);
        assertEquals("Juan Pérez López", e.nombreProbable("4471"));
    }
}
